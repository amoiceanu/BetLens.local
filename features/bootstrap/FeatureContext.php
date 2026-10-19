<?php

declare(strict_types=1);

use App\Models\GeneratedTicket;
use App\Models\Operator;
use App\Models\User;
use Behat\Behat\Context\Context;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class FeatureContext implements Context
{
    private static ?\Illuminate\Foundation\Application $app = null;

    private ?GeneratedTicket $ticket = null;

    private ?Response $response = null;

    private ?User $ticketUser = null;

    private ?User $adminUser = null;

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

        $this->expect(self::$app->environment() === 'testing', 'Behat must run in the testing environment.');
        $this->expect(config('database.default') === 'sqlite', 'Behat must use SQLite.');
        $this->expect(config('database.connections.sqlite.database') === ':memory:', 'Behat must use in-memory SQLite.');

        Artisan::call('migrate:fresh', ['--force' => true, '--quiet' => true]);
        Auth::logout();
        session()->flush();
        $this->ticketUser = User::factory()->create(['is_admin' => false]);
        $this->adminUser = User::factory()->create(['is_admin' => true]);
        $this->ticket = null;
        $this->response = null;
    }

    #[Given('a ticket exists with reference :reference')]
    public function aTicketExists(string $reference): void
    {
        $this->ticket = GeneratedTicket::create([
            'user_id' => $this->ticketUser?->id,
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
        session()->forget('admin_user_id');
        Auth::login($this->ticketUser);
        $this->request('GET', '/bilete');
    }

    #[When('I open the data sources page')]
    public function openDataSources(): void
    {
        Auth::login($this->adminUser);
        session()->put('admin_user_id', $this->adminUser?->id);
        $this->request('GET', '/surse-date');
    }

    #[When('I save the ticket with reference :reference, operator :operator, first match :first and last match :last')]
    public function saveTicket(string $reference, string $operator, string $first, string $last): void
    {
        session()->forget('admin_user_id');
        Auth::login($this->ticketUser);
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
        $this->expect($this->response !== null, 'No HTTP response was recorded.');
        $this->expect($this->response->getStatusCode() === 200,
            'Expected HTTP 200, got '.$this->response->getStatusCode().'.');
    }

    #[Then('I see :text in the response')]
    public function seeText(string $text): void
    {
        $this->expect($this->response !== null, 'No HTTP response was recorded.');
        $this->expect(str_contains($this->response->getContent(), $text), 'The response does not contain: '.$text);
    }

    #[Then('the ticket has reference :reference and status :status on operator :operator')]
    public function ticketHasStatus(string $reference, string $status, string $operator): void
    {
        $this->expect($this->response?->getStatusCode() === 302,
            'Expected HTTP 302, got '.($this->response?->getStatusCode() ?? 'no response').'.');
        $this->expect(session('success') === 'Detaliile biletului au fost actualizate.', 'The update success message is missing.');
        $this->ticket?->refresh();
        $this->expect($this->ticket?->reference === $reference, 'The ticket reference was not updated.');
        $this->expect($this->ticket?->status === $status, 'The ticket status was not updated.');
        $this->expect($this->ticket?->operator?->name === $operator, 'The ticket operator was not updated.');
    }

    #[Then('the ticket match interval is :first to :last')]
    public function ticketMatchInterval(string $first, string $last): void
    {
        $this->ticket?->refresh();
        $this->expect($this->ticket?->first_match_at?->format('Y-m-d H:i') === $first, 'The first match time differs.');
        $this->expect($this->ticket?->last_match_at?->format('Y-m-d H:i') === $last, 'The last match time differs.');
    }

    #[Then('the ticket still has reference :reference and status :status')]
    public function ticketWasNotChanged(string $reference, string $status): void
    {
        $this->expect($this->response?->getStatusCode() === 302,
            'Expected HTTP 302, got '.($this->response?->getStatusCode() ?? 'no response').'.');
        $this->expect(session()->has('errors'), 'Validation errors were not returned.');
        $this->ticket?->refresh();
        $this->expect($this->ticket?->reference === $reference, 'The invalid update changed the ticket reference.');
        $this->expect($this->ticket?->status === $status, 'The invalid update changed the ticket status.');
        $this->expect($this->ticket?->first_match_at === null, 'The invalid update changed the first match time.');
    }

    private function request(string $method, string $uri, array $parameters = []): void
    {
        $request = Request::create($uri, $method, $parameters);
        $kernel = self::$app->make(HttpKernel::class);
        $this->response = $kernel->handle($request);
        $kernel->terminate($request, $this->response);
    }

    private function expect(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new \RuntimeException($message);
        }
    }
}
