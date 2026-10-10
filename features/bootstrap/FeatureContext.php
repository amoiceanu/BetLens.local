<?php

declare(strict_types=1);

use App\Models\GeneratedTicket;
use App\Models\Operator;
use Behat\Behat\Context\Context;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

final class FeatureContext implements Context
{
    private static ?\Illuminate\Foundation\Application $app = null;

    private ?GeneratedTicket $ticket = null;

    private ?Response $response = null;

    #[BeforeScenario]
    public function prepareScenario(): void
    {
        if (self::$app === null) {
            foreach ([
                'APP_ENV' => 'testing',
                'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                'APP_URL' => 'http://localhost',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
                'SESSION_DRIVER' => 'array',
                'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
            ] as $key => $value) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }

            self::$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
            self::$app->make(ConsoleKernel::class)->bootstrap();
        }

        Assert::assertSame('testing', self::$app->environment());
        Assert::assertSame('sqlite', config('database.default'));
        Assert::assertSame(':memory:', config('database.connections.sqlite.database'));

        Artisan::call('migrate:fresh', ['--force' => true, '--quiet' => true]);
        $this->ticket = null;
        $this->response = null;
    }

    #[Given('a ticket exists with reference :reference')]
    public function aTicketExists(string $reference): void
    {
        $this->ticket = GeneratedTicket::create([
            'reference' => $reference,
            'risk_profile' => 'conservator',
            'total_odds' => 1.5,
            'combined_probability' => 0.66,
            'status' => 'pending',
        ]);
    }

    #[When('I open the ticket history')]
    public function openTicketHistory(): void
    {
        $this->request('GET', '/bilete');
    }

    #[When('I open the data sources page')]
    public function openDataSources(): void
    {
        $this->request('GET', '/surse-date');
    }

    #[When('I save the ticket with reference :reference, operator :operator, first match :first and last match :last')]
    public function saveTicket(string $reference, string $operator, string $first, string $last): void
    {
        $operatorId = Operator::where('name', $operator)->firstOrFail()->id;
        $this->request('PATCH', '/bilete/'.$this->ticket?->id, [
            'reference' => $reference,
            'status' => 'placed:'.$operatorId,
            'first_match_at' => $first,
            'last_match_at' => $last,
        ]);
    }

    #[Then('the response is successful')]
    public function responseIsSuccessful(): void
    {
        Assert::assertNotNull($this->response);
        Assert::assertSame(200, $this->response->getStatusCode());
    }

    #[Then('I see :text in the response')]
    public function seeText(string $text): void
    {
        Assert::assertNotNull($this->response);
        Assert::assertStringContainsString($text, $this->response->getContent());
    }

    #[Then('the ticket has reference :reference and status :status on operator :operator')]
    public function ticketHasStatus(string $reference, string $status, string $operator): void
    {
        Assert::assertSame(302, $this->response?->getStatusCode());
        Assert::assertSame('Detaliile biletului au fost actualizate.', session('success'));
        $this->ticket?->refresh();
        Assert::assertSame($reference, $this->ticket?->reference);
        Assert::assertSame($status, $this->ticket?->status);
        Assert::assertSame($operator, $this->ticket?->operator?->name);
    }

    #[Then('the ticket match interval is :first to :last')]
    public function ticketMatchInterval(string $first, string $last): void
    {
        $this->ticket?->refresh();
        Assert::assertSame($first, $this->ticket?->first_match_at?->format('Y-m-d H:i'));
        Assert::assertSame($last, $this->ticket?->last_match_at?->format('Y-m-d H:i'));
    }

    #[Then('the ticket still has reference :reference and status :status')]
    public function ticketWasNotChanged(string $reference, string $status): void
    {
        Assert::assertSame(302, $this->response?->getStatusCode());
        Assert::assertTrue(session()->has('errors'));
        $this->ticket?->refresh();
        Assert::assertSame($reference, $this->ticket?->reference);
        Assert::assertSame($status, $this->ticket?->status);
        Assert::assertNull($this->ticket?->first_match_at);
    }

    private function request(string $method, string $uri, array $parameters = []): void
    {
        $request = Request::create($uri, $method, $parameters);
        $kernel = self::$app->make(HttpKernel::class);
        $this->response = $kernel->handle($request);
        $kernel->terminate($request, $this->response);
    }
}
