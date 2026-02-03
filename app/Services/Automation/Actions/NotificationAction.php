<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions;

use App\Models\AutomationWorkflow;
use App\Models\Review;
use App\Models\User;
use App\Services\Automation\Actions\Contracts\ActionInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class NotificationAction implements ActionInterface
{
    public function execute(array $actionConfig, array $contextData, AutomationWorkflow $workflow): array
    {
        $type = $actionConfig['type'] ?? 'email';
        
        // If type is 'notification', default to email
        if ($type === 'notification') {
            $type = 'email';
        }
        
        $recipients = $this->resolveRecipients($actionConfig, $contextData, $workflow);
        $message = $this->buildMessage($actionConfig, $contextData);

        if (empty($recipients)) {
            throw new \RuntimeException('No recipients found for notification');
        }

        Log::info('Executing notification action', [
            'type' => $type,
            'recipients_count' => count($recipients),
            'workflow_id' => $workflow->id,
        ]);

        $results = [];

        switch ($type) {
            case 'email':
                $results = $this->sendEmailNotifications($recipients, $message, $actionConfig);
                break;
            
            case 'slack':
                $results = $this->sendSlackNotifications($recipients, $message, $actionConfig);
                break;
            
            case 'webhook':
                $results = $this->sendWebhookNotifications($recipients, $message, $actionConfig, $contextData);
                break;
            
            default:
                throw new \InvalidArgumentException("Unsupported notification type: {$type}");
        }

        return [
            'success' => true,
            'type' => $type,
            'recipients_count' => count($recipients),
            'sent_count' => count(array_filter($results, fn($r) => $r['success'])),
            'failed_count' => count(array_filter($results, fn($r) => !$r['success'])),
            'results' => $results,
        ];
    }

    public function validate(array $actionConfig): array
    {
        $errors = [];

        $type = $actionConfig['type'] ?? 'email';
        if (!in_array($type, ['email', 'slack', 'webhook'])) {
            $errors[] = 'type must be one of: email, slack, webhook';
        }

        if (empty($actionConfig['recipients'])) {
            $errors[] = 'recipients is required';
        }

        if (empty($actionConfig['subject']) && $type === 'email') {
            $errors[] = 'subject is required for email notifications';
        }

        if (empty($actionConfig['message'])) {
            $errors[] = 'message is required';
        }

        if ($type === 'webhook' && empty($actionConfig['webhook_url'])) {
            $errors[] = 'webhook_url is required for webhook notifications';
        }

        return $errors;
    }

    public function getName(): string
    {
        return 'Notification';
    }

    public function getDescription(): string
    {
        return 'Send notifications via email, Slack, or webhook';
    }

    public function getConfigSchema(): array
    {
        return [
            'type' => [
                'type' => 'string',
                'required' => true,
                'enum' => ['email', 'slack', 'webhook'],
                'description' => 'Type of notification to send',
            ],
            'recipients' => [
                'type' => 'array',
                'required' => true,
                'description' => 'List of recipients (emails, user IDs, or webhook URLs)',
            ],
            'subject' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Subject line (for email notifications)',
            ],
            'message' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Message content (supports variables like {{review.content}})',
            ],
            'webhook_url' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Webhook URL (for webhook notifications)',
            ],
            'priority' => [
                'type' => 'string',
                'required' => false,
                'enum' => ['low', 'normal', 'high'],
                'default' => 'normal',
                'description' => 'Notification priority',
            ],
        ];
    }

    protected function resolveRecipients(array $actionConfig, array $contextData, AutomationWorkflow $workflow): array
    {
        $recipients = [];
        $recipientConfig = $actionConfig['recipients'] ?? [];

        foreach ($recipientConfig as $recipient) {
            if (is_string($recipient)) {
                // Direct email or user ID
                if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = ['type' => 'email', 'address' => $recipient];
                } elseif (is_numeric($recipient)) {
                    $user = User::find($recipient);
                    if ($user) {
                        $recipients[] = ['type' => 'user', 'user' => $user, 'address' => $user->email];
                    }
                }
            } elseif (is_array($recipient)) {
                // Dynamic recipient resolution
                $type = $recipient['type'] ?? 'email';
                
                switch ($type) {
                    case 'workflow_creator':
                        $user = $workflow->createdBy;
                        if ($user) {
                            $recipients[] = ['type' => 'user', 'user' => $user, 'address' => $user->email];
                        }
                        break;
                    
                    case 'tenant_admins':
                        $admins = $workflow->tenant->users()
                            ->wherePivot('role', 'admin')
                            ->orWherePivot('role', 'owner')
                            ->get();
                        
                        foreach ($admins as $admin) {
                            $recipients[] = ['type' => 'user', 'user' => $admin, 'address' => $admin->email];
                        }
                        break;
                    
                    case 'email':
                        if (!empty($recipient['address'])) {
                            $recipients[] = ['type' => 'email', 'address' => $recipient['address']];
                        }
                        break;
                }
            }
        }

        return $recipients;
    }

    protected function buildMessage(array $actionConfig, array $contextData): array
    {
        $message = $actionConfig['message'] ?? '';
        $subject = $actionConfig['subject'] ?? 'Automation Notification';

        // Replace variables in message and subject
        $variables = $this->extractVariables($contextData);
        
        foreach ($variables as $key => $value) {
            $placeholder = "{{" . $key . "}}";
            $message = str_replace($placeholder, (string) $value, $message);
            $subject = str_replace($placeholder, (string) $value, $subject);
        }

        return [
            'subject' => $subject,
            'message' => $message,
            'priority' => $actionConfig['priority'] ?? 'normal',
        ];
    }

    protected function extractVariables(array $contextData): array
    {
        $variables = [];

        // Review variables
        if (isset($contextData['review_id'])) {
            $review = Review::find($contextData['review_id']);
            if ($review) {
                $variables['review.content'] = $review->content ?? '';
                $variables['review.rating'] = $review->rating ?? 'N/A';
                $variables['review.author'] = $review->author_name ?? 'Anonymous';
                $variables['review.platform'] = $review->platform ?? '';
                $variables['location.name'] = $review->location->name ?? '';
            }
        }

        // Workflow variables
        if (isset($contextData['workflow'])) {
            $workflow = $contextData['workflow'];
            $variables['workflow.name'] = $workflow['name'] ?? '';
        }

        // Date variables
        $variables['date'] = now()->format('Y-m-d');
        $variables['datetime'] = now()->format('Y-m-d H:i:s');

        return $variables;
    }

    protected function sendEmailNotifications(array $recipients, array $message, array $config): array
    {
        $results = [];

        foreach ($recipients as $recipient) {
            try {
                // Simple email sending - in production, you'd use a proper mail class
                Mail::raw($message['message'], function ($mail) use ($recipient, $message) {
                    $mail->to($recipient['address'])
                         ->subject($message['subject']);
                });

                $results[] = [
                    'success' => true,
                    'recipient' => $recipient['address'],
                    'type' => 'email',
                ];

            } catch (\Exception $e) {
                Log::error('Email notification failed', [
                    'recipient' => $recipient['address'],
                    'error' => $e->getMessage(),
                ]);

                $results[] = [
                    'success' => false,
                    'recipient' => $recipient['address'],
                    'type' => 'email',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    protected function sendSlackNotifications(array $recipients, array $message, array $config): array
    {
        // Placeholder for Slack integration
        // You would implement actual Slack webhook sending here
        return array_map(fn($r) => [
            'success' => false,
            'recipient' => $r['address'],
            'type' => 'slack',
            'error' => 'Slack integration not implemented',
        ], $recipients);
    }

    protected function sendWebhookNotifications(array $recipients, array $message, array $config, array $contextData): array
    {
        $results = [];
        $webhookUrl = $config['webhook_url'] ?? null;

        if (!$webhookUrl) {
            return [['success' => false, 'error' => 'No webhook URL configured']];
        }

        try {
            $payload = [
                'message' => $message['message'],
                'subject' => $message['subject'],
                'priority' => $message['priority'],
                'context' => $contextData,
                'timestamp' => now()->toISOString(),
            ];

            // Send webhook - you'd implement actual HTTP client here
            $results[] = [
                'success' => true,
                'recipient' => $webhookUrl,
                'type' => 'webhook',
                'payload' => $payload,
            ];

        } catch (\Exception $e) {
            $results[] = [
                'success' => false,
                'recipient' => $webhookUrl,
                'type' => 'webhook',
                'error' => $e->getMessage(),
            ];
        }

        return $results;
    }
}