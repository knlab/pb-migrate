<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit;

use JsonSchema\Validator;
use KnLab\PbMigrate\Command\InitCommand;
use PHPUnit\Framework\TestCase;

/**
 * Catches drift between docs/schema.json and the rest of the project — the
 * config templates we ship (sample project + scaffolded `init` output) MUST
 * validate against the schema. If someone adds a new field to BotConfig or
 * the InitCommand template without updating the schema (or vice versa), this
 * test fails loudly at CI time.
 */
final class SchemaValidationTest extends TestCase
{
    private static string $schemaPath = __DIR__ . '/../../docs/schema.json';

    public function testSchemaFileIsValidJson(): void
    {
        $raw = file_get_contents(self::$schemaPath);
        $this->assertNotFalse($raw, 'docs/schema.json must be readable');

        $decoded = json_decode($raw);
        $this->assertNotNull($decoded, sprintf('docs/schema.json must be valid JSON: %s', json_last_error_msg()));
        $this->assertIsObject($decoded, 'docs/schema.json root must be an object');
    }

    public function testExampleSampleProjectConfigPassesSchema(): void
    {
        $configPath = __DIR__ . '/../../examples/sample-project/pb-migrate.json';
        $this->assertSchemaPasses($configPath);
    }

    public function testInitCommandGeneratedConfigPassesSchema(): void
    {
        $template = $this->invokeInitConfigTemplate('mybot');
        $data = json_decode($template);
        $this->assertNotNull($data, 'InitCommand template must produce valid JSON');

        $validator = new Validator();
        $validator->validate($data, (object) ['$ref' => 'file://' . realpath(self::$schemaPath)]);
        $this->assertTrue(
            $validator->isValid(),
            sprintf('InitCommand template should validate against the schema. Errors: %s', $this->formatErrors($validator)),
        );
    }

    public function testInitCommandGeneratedConfigPassesSchemaForArbitraryBotName(): void
    {
        // Cover the alphanumeric-and-_- character class on the bots map key.
        $template = $this->invokeInitConfigTemplate('prod_greeter-2');
        $data = json_decode($template);
        $this->assertNotNull($data);

        $validator = new Validator();
        $validator->validate($data, (object) ['$ref' => 'file://' . realpath(self::$schemaPath)]);
        $this->assertTrue(
            $validator->isValid(),
            sprintf('Generated config with mixed-case underscore bot name should pass: %s', $this->formatErrors($validator)),
        );
    }

    public function testSchemaRejectsConfigMissingRequiredField(): void
    {
        $bad = (object) [
            'host' => 'https://api.pandorabots.com',
            // Intentionally missing appId / userKey / bots
        ];
        $validator = new Validator();
        $validator->validate($bad, (object) ['$ref' => 'file://' . realpath(self::$schemaPath)]);
        $this->assertFalse($validator->isValid(), 'config missing required fields should fail validation');
    }

    public function testSchemaRejectsBotMissingDirectory(): void
    {
        $bad = (object) [
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => (object) [
                'mybot' => (object) ['files' => '*'],
            ],
        ];
        $validator = new Validator();
        $validator->validate($bad, (object) ['$ref' => 'file://' . realpath(self::$schemaPath)]);
        $this->assertFalse($validator->isValid(), 'bot missing required `directory` should fail validation');
    }

    public function testSchemaRejectsUnknownPropertyOnBot(): void
    {
        $bad = (object) [
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => (object) [
                'mybot' => (object) [
                    'directory' => './aiml/mybot',
                    'directry' => './typo', // intentional typo to confirm additionalProperties: false
                ],
            ],
        ];
        $validator = new Validator();
        $validator->validate($bad, (object) ['$ref' => 'file://' . realpath(self::$schemaPath)]);
        $this->assertFalse($validator->isValid(), 'typo on bot config should be flagged by additionalProperties:false');
    }

    public function testSchemaAcceptsAltersMap(): void
    {
        $config = (object) [
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => (object) [
                'mybot' => (object) [
                    'directory' => './aiml/mybot',
                    'alters' => (object) [
                        '_dump_predicates' => 'variants/dump-predicates.aiml',
                        'greet' => 'variants/greet-debug.aiml',
                    ],
                ],
            ],
        ];
        $validator = new Validator();
        $validator->validate($config, (object) ['$ref' => 'file://' . realpath(self::$schemaPath)]);
        $this->assertTrue(
            $validator->isValid(),
            sprintf('alters map should validate. Errors: %s', $this->formatErrors($validator)),
        );
    }

    public function testSchemaAcceptsPropertiesUploadFull(): void
    {
        $config = (object) [
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => (object) [
                'mybot' => (object) [
                    'directory' => './aiml/mybot',
                    'propertiesUpload' => 'full',
                ],
            ],
        ];
        $validator = new Validator();
        $validator->validate($config, (object) ['$ref' => 'file://' . realpath(self::$schemaPath)]);
        $this->assertTrue(
            $validator->isValid(),
            sprintf('propertiesUpload=full should validate. Errors: %s', $this->formatErrors($validator)),
        );
    }

    public function testSchemaRejectsInvalidPropertiesUploadValue(): void
    {
        $config = (object) [
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => (object) [
                'mybot' => (object) [
                    'directory' => './aiml/mybot',
                    'propertiesUpload' => 'sometimes', // not in enum
                ],
            ],
        ];
        $validator = new Validator();
        $validator->validate($config, (object) ['$ref' => 'file://' . realpath(self::$schemaPath)]);
        $this->assertFalse($validator->isValid(), 'invalid propertiesUpload value should fail validation');
    }

    private function assertSchemaPasses(string $configPath): void
    {
        $raw = file_get_contents($configPath);
        $this->assertNotFalse($raw, sprintf('config file %s must be readable', $configPath));
        $data = json_decode($raw);
        $this->assertNotNull($data, sprintf('config file %s must be valid JSON', $configPath));

        $validator = new Validator();
        $validator->validate($data, (object) ['$ref' => 'file://' . realpath(self::$schemaPath)]);
        $this->assertTrue(
            $validator->isValid(),
            sprintf('%s should validate against docs/schema.json. Errors: %s', $configPath, $this->formatErrors($validator)),
        );
    }

    private function invokeInitConfigTemplate(string $botname): string
    {
        $reflection = new \ReflectionClass(InitCommand::class);
        $method = $reflection->getMethod('configTemplate');
        return (string) $method->invoke(null, $botname);
    }

    private function formatErrors(Validator $validator): string
    {
        $errors = $validator->getErrors();
        if ($errors === []) {
            return '(none)';
        }
        return implode('; ', array_map(
            static fn (array $e) => sprintf('%s: %s', $e['property'] ?? '(root)', $e['message']),
            $errors,
        ));
    }
}
