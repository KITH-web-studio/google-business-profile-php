<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use GoogleBusinessProfile\Client;
use GoogleBusinessProfile\PubSub\AuthenticationException;
use GoogleBusinessProfile\PubSub\PushReceiver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class GoogleBusinessProfileWebhookController
{
    public function __invoke(Request $request, PushReceiver $receiver, Client $sdk): Response
    {
        try {
            $notification = $receiver->receive($request->header('Authorization'), $request->getContent());
        } catch (AuthenticationException) {
            return new Response('', 401);
        } catch (InvalidArgumentException) {
            return new Response('', 400);
        }

        if ($notification->type !== 'NEW_REVIEW') {
            return new Response('', 204);
        }

        try {
            $reviewName = $notification->reviewName(config('services.google_business_profile.account'));
        } catch (InvalidArgumentException) {
            return new Response('', 400);
        }

        $review = $sdk->myBusiness()->reviewsGet($reviewName);

        Log::info('New Google Business Profile review', [
            'message_id' => $notification->messageId,
            'review_name' => $reviewName,
            'star_rating' => $review['starRating'] ?? null,
        ]);

        return new Response('', 204);
    }
}
