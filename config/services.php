<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],
	
	    'mtarget' => [
        'url' => env('MTARGET_URL', 'https://api-public-2.mtarget.fr/messages'),
        'username' => env('MTARGET_USERNAME', 'bwantech'),
        'password' => env('MTARGET_PASSWORD', 'x7jyKG0IJRNH'),
        'sender' => env('MTARGET_SENDER', 'TOO AUTO'),
        'timeout' => env('MTARGET_TIMEOUT', 30),
        'verify_ssl' => env('MTARGET_VERIFY_SSL', false),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
