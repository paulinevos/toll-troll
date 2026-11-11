<?php

namespace Vos\TollTroll\Examples;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification as Notifications;
use Vos\TollTroll\Events\ReviewRequired;

class ReviewNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $evaluationId) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Agent context needs review')
            ->line('Review evaluation '.$this->evaluationId.' in your application before authorizing work.');
    }
}

// Register once in an application service provider. Select the reviewer in
// trusted application code, never from a model's classification or context.
return function (object $reviewer): void {
    Event::listen(ReviewRequired::class, function (ReviewRequired $event) use ($reviewer): void {
        Notifications::send($reviewer, new ReviewNotification($event->evaluation->id));
    });
};
