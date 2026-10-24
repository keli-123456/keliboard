<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AgentUser;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\User;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;

final class GiftCardMysqlFixture
{
    use InteractsWithInMemoryDatabase;

    public const DATABASE = 'keli_acceptance_giftcard';
    public const USER = 'keli_acceptance';
    private ?string $owner = null;

    public static function configuration(array $environment): array
    {
        if (($environment['GITHUB_ACTIONS'] ?? '') !== 'true'
            || ($environment['KELI_GIFTCARD_MYSQL_ACCEPTANCE'] ?? '') !== '1'
            || ($environment['KELI_GIFTCARD_DB_HOST'] ?? '') !== '127.0.0.1'
            || ($environment['KELI_GIFTCARD_DB_NAME'] ?? '') !== self::DATABASE
            || ($environment['KELI_GIFTCARD_DB_USER'] ?? '') !== self::USER
            || !is_string($environment['KELI_GIFTCARD_DB_PASSWORD'] ?? null)
            || trim($environment['KELI_GIFTCARD_DB_PASSWORD']) === ''
            || !ctype_digit((string) ($environment['KELI_GIFTCARD_DB_PORT'] ?? ''))
            || (int) $environment['KELI_GIFTCARD_DB_PORT'] < 1024
            || (int) $environment['KELI_GIFTCARD_DB_PORT'] > 65535
            || !in_array($environment['KELI_GIFTCARD_ISOLATION'] ?? '', ['REPEATABLE READ', 'READ COMMITTED'], true)) {
            throw new \RuntimeException('Only the explicitly enabled, loopback CI MySQL fixture is allowed.');
        }
        return ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => (int) $environment['KELI_GIFTCARD_DB_PORT'],
            'database' => self::DATABASE, 'username' => self::USER,
            'password' => $environment['KELI_GIFTCARD_DB_PASSWORD'], 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_bin', 'prefix' => '', 'strict' => true, 'engine' => 'InnoDB',
            'isolation_level' => $environment['KELI_GIFTCARD_ISOLATION'],
            'options' => [\PDO::ATTR_TIMEOUT => 5]];
    }

    public function __construct()
    {
        $configuration = self::configuration(getenv());
        if (!extension_loaded('pdo_mysql')) {
            throw new \RuntimeException('pdo_mysql is required for the acceptance test.');
        }
        $app = new Application(dirname(__DIR__, 2));
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app->instance('app', $app);
        $app->instance('config', new Repository());
        $app->instance('translator', new Translator(new ArrayLoader(), 'en'));
        $app->instance('hook.actions', []);
        $app->instance('hook.filters', []);
        $app->instance('log', new class {
            public function info(...$arguments): void {}
            public function warning(...$arguments): void {}
            public function error(...$arguments): void {}
        });
        Model::reguard();
        Model::unsetEventDispatcher();
        $this->database = new Capsule($app);
        $this->database->addConnection($configuration);
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        $app->instance('db', $this->database->getDatabaseManager());
        $app->instance('db.connection', $this->database->getConnection());
        $app->instance('db.schema', $this->database->schema());
        Model::setConnectionResolver($this->database->getDatabaseManager());
        $this->bindTestSettings(['agent_center_enable' => 1, 'agent_center_discount_percent' => 100,
            'agent_center_bonus_day_price' => 100, 'agent_center_allow_traffic_reset' => 1,
            'agent_center_reset_price_mode' => 'plan_reset_price']);

        $identity = $this->connection()->selectOne('SELECT DATABASE() AS db_name, CURRENT_USER() AS db_user,
            @@version AS version, @@transaction_isolation AS isolation_level');
        if ($identity->db_name !== self::DATABASE || !str_starts_with($identity->db_user, self::USER . '@')
            || !str_starts_with($identity->version, '8.4.')
            || str_replace('-', ' ', $identity->isolation_level) !== $configuration['isolation_level']) {
            throw new \RuntimeException('The MySQL fixture identity or isolation level differs from the test contract.');
        }
        foreach ($this->connection()->select('SHOW GRANTS') as $grant) {
            $text = (string) array_values((array) $grant)[0];
            if (str_contains($text, ' ON *.* ') && !str_starts_with($text, 'GRANT USAGE ON *.* ')) {
                throw new \RuntimeException('The fixture account must not have global database privileges.');
            }
        }
    }

    public function connection(): \Illuminate\Database\Connection
    {
        return $this->database->getConnection();
    }

    public function createSchema(): string
    {
        if ($this->connection()->select('SHOW TABLES') !== []) {
            throw new \RuntimeException('The CI acceptance database must be empty.');
        }
        $owner = bin2hex(random_bytes(16));
        $this->database->schema()->create('keli_acceptance_owner', function (Blueprint $table): void {
            $table->string('owner', 32)->primary();
        });
        $this->connection()->table('keli_acceptance_owner')->insert(['owner' => $owner]);
        $this->owner = $owner;
        $this->createUserTable();
        $this->createPlanTable();
        $this->createAgentCenterTables();
        $this->createOrderTable();
        $this->createPaymentTable();
        foreach (['2025_07_01_122908_create_gift_card_tables.php', '2026_06_23_000004_scope_gift_cards_by_tenant.php'] as $file) {
            $migration = require dirname(__DIR__, 2) . '/database/migrations/' . $file;
            $migration->up();
        }
        $engines = $this->connection()->select('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        foreach ($engines as $engine) {
            if ($engine->engine !== 'InnoDB') {
                throw new \RuntimeException('Every acceptance table must use InnoDB.');
            }
        }
        return $owner;
    }

    public function assertOwner(string $owner): void
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/', $owner)
            || $this->connection()->table('keli_acceptance_owner')->value('owner') !== $owner) {
            throw new \RuntimeException('The acceptance fixture ownership marker does not match.');
        }
    }

    public function cleanup(): void
    {
        if ($this->owner === null) {
            return;
        }
        $this->assertOwner($this->owner);
        foreach ($this->connection()->select('SHOW TABLES') as $row) {
            $table = (string) array_values((array) $row)[0];
            if ($table !== 'keli_acceptance_owner') {
                $this->database->schema()->drop($table);
            }
        }
        $this->database->schema()->drop('keli_acceptance_owner');
        $this->owner = null;
    }

    public function user(array $attributes = []): User
    {
        $id = bin2hex(random_bytes(8));
        return User::create(array_replace(['email' => 'fixture-' . $id . '@example.test', 'password' => 'fixture',
            'uuid' => 'fixture-' . $id, 'token' => 'fixture-' . $id, 'balance' => 0, 'transfer_enable' => 0,
            'u' => 0, 'd' => 0, 'expired_at' => time() + 86400, 'created_at' => time(), 'updated_at' => time()], $attributes));
    }

    public function paymentRouter(): \Illuminate\Routing\Router
    {
        $this->bindJsonResponseFactory();
        $this->bindSynchronousBusDispatcher();
        $this->bindTestRouter('https://fixture.example.test');
        $plugin = new \Plugin\Epay\Plugin('epay');
        $plugin->boot();
        app()->instance(\App\Services\Plugin\PluginManager::class,
            new class($plugin) extends \App\Services\Plugin\PluginManager {
                public function __construct(private \Plugin\Epay\Plugin $plugin) {}

                public function getEnabledPaymentPlugins(): array { return [$this->plugin]; }
            });
        $router = app('router');
        $router->post('/api/v1/guest/payment/notify/{method}/{uuid}', [\App\Http\Controllers\V1\Guest\PaymentController::class, 'notify']);
        return $router;
    }

    public function agent(int $balance = 10000): User
    {
        $user = $this->user(['balance' => $balance]);
        $this->connection()->table('v2_agent_profile')->insert(['user_id' => $user->id, 'status' => 'active',
            'level' => 'default', 'enabled_at' => time(), 'created_at' => time(), 'updated_at' => time()]);
        return $user;
    }

    public function subordinate(User $agent): User
    {
        $user = $this->user();
        AgentUser::create(['agent_user_id' => $agent->id, 'sub_user_id' => $user->id,
            'created_at' => time(), 'updated_at' => time()]);
        return $user;
    }

    public function card(array $attributes = []): GiftCardCode
    {
        $scope = array_intersect_key($attributes, array_flip(['scope_type', 'site_id', 'agent_user_id', 'agent_domain_id']));
        $template = GiftCardTemplate::create(array_replace(['name' => 'Acceptance fixture', 'type' => GiftCardTemplate::TYPE_GENERAL,
            'status' => 1, 'rewards' => $attributes['rewards'] ?? ['balance' => 100, 'transfer_enable' => 1000],
            'limits' => $attributes['limits'] ?? null, 'admin_id' => 1], $scope));
        return $this->code($template->id, $scope + ['max_usage' => $attributes['max_usage'] ?? 1]);
    }

    public function code(int $templateId, array $attributes = []): GiftCardCode
    {
        return GiftCardCode::create(array_replace(['template_id' => $templateId, 'code' => strtoupper(bin2hex(random_bytes(12))),
            'status' => GiftCardCode::STATUS_UNUSED, 'max_usage' => 1, 'usage_count' => 0], $attributes));
    }
}
