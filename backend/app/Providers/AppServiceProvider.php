<?php

namespace App\Providers;

use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PayMongoGateway;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Database\DatabaseContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use SocialiteProviders\Apple\Provider as AppleProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One context per request/job (Octane- and queue-safe).
        $this->app->scoped(DatabaseContext::class);
        $this->app->scoped(OrganizationAccess::class);

        // B10: payment driver. Capstone builds run "fake" or "test"; "live"
        // (real money) is refused outside production, and "test" refuses live keys.
        $this->app->singleton(PaymentGateway::class, function () {
            $driver = (string) config('services.paymongo.driver');
            $key = (string) config('services.paymongo.secret_key');

            return match ($driver) {
                'fake' => new FakeGateway,
                'test' => str_starts_with($key, 'sk_test_')
                    ? new PayMongoGateway('test')
                    : throw new RuntimeException('PAYMONGO_DRIVER=test needs a PayMongo TEST secret key (sk_test_…).'),
                'live' => $this->app->isProduction() && str_starts_with($key, 'sk_live_')
                    ? new PayMongoGateway('live')
                    : throw new RuntimeException('Live PayMongo payments are only allowed in production with a live key.'),
                default => throw new RuntimeException("Unknown PAYMONGO_DRIVER \"{$driver}\"."),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Production sits behind Cloudflare's tunnel and Apache's /api proxy, which reach
        // Laravel as 127.0.0.1: links Laravel builds itself use the public address instead.
        if ($this->app->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceRootUrl((string) config('app.url'));
            URL::forceScheme('https');
        }

        $this->configureRateLimiting();

        // Password policy for registration, reset and change. The breach check
        // (Have I Been Pwned, k-anonymity) runs only where outbound calls are
        // expected, so tests and offline development stay deterministic.
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(8)->max(128)->letters()->numbers()->uncompromised()
            : Password::min(8)->max(128)->letters()->numbers());

        Event::listen(fn (SocialiteWasCalled $event) => $event->extendSocialite('apple', AppleProvider::class));
    }

    /**
     * Named limiters from config('nasery.rate_limits'); use as
     * ->middleware('throttle:login') etc.
     */
    private function configureRateLimiting(): void
    {
        foreach (config('nasery.rate_limits', []) as $name => [$max, $minutes, $keyBy]) {
            RateLimiter::for($name, function (Request $request) use ($name, $max, $minutes, $keyBy) {
                $userId = $request->user()?->getAuthIdentifier();

                $key = match ($keyBy) {
                    'user' => $userId !== null ? "user:{$userId}" : "ip:{$request->ip()}",
                    'user_or_ip' => $userId !== null ? "user:{$userId}" : "ip:{$request->ip()}",
                    'email' => 'email:'.hash('sha256', mb_strtolower(trim((string) $request->input('email')))),
                    default => "ip:{$request->ip()}",
                };

                return Limit::perMinutes($minutes, $max)->by("{$name}|{$key}");
            });
        }
    }
}
