<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit;

use JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Catches drift between docs/schema.json and the rest of the project — the
 * config templates we ship (sample project, etc.) MUST validate against the
 * schema. If someone adds a new field to BotConfig without updating the
 * schema (or vice versa), this test fails loudly.
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

    public function testSchemaRejectsConfigMissingBots(): void
    {
        $bad = (object) [];  // no bots map
        $this->assertSchemaFails($bad);
    }

    public function testSchemaRejectsBotMissingDirectory(): void
    {
        $bad = (object) [
            'bots' => (object) [
                'mybot' => (object) [],  // no directory
            ],
        ];
        $this->assertSchemaFails($bad);
    }

    public function testSchemaRejectsUnknownPropertyOnBot(): void
    {
        $bad = (object) [
            'bots' => (object) [
                'mybot' => (object) [
                    'directory' => './aiml/mybot',
                    'directry' => './typo',  // intentional typo
                ],
            ],
        ];
        $this->assertSchemaFails($bad);
    }

    public function testSchemaRejectsLegacyTopLevelCredentialFields(): void
    {
        $legacy = (object) [
            'host' => 'https://api.pandorabots.com',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'bots' => (object) [
                'mybot' => (object) ['directory' => './aiml/mybot'],
            ],
        ];
        $this->assertSchemaFails($legacy, 'top-level credentials should no longer pass the v0.7+ schema');
    }

    public function testSchemaRejectsLegacyFilesField(): void
    {
        $legacy = (object) [
            'bots' => (object) [
                'mybot' => (object) [
                    'directory' => './aiml/mybot',
                    'files' => '*',  // removed in v0.7
                ],
            ],
        ];
        $this->assertSchemaFails($legacy);
    }

    public function testSchemaAcceptsAltersMap(): void
    {
        $config = (object) [
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
        $this->assertSchemaAccepts($config);
    }

    public function testSchemaAcceptsPropertiesUploadFull(): void
    {
        $config = (object) [
            'bots' => (object) [
                'mybot' => (object) [
                    'directory' => './aiml/mybot',
                    'propertiesUpload' => 'full',
                ],
            ],
        ];
        $this->assertSchemaAccepts($config);
    }

    public function testSchemaRejectsInvalidPropertiesUploadValue(): void
    {
        $bad = (object) [
            'bots' => (object) [
                'mybot' => (object) [
                    'directory' => './aiml/mybot',
                    'propertiesUpload' => 'sometimes',  // not in enum
                ],
            ],
        ];
        $this->assertSchemaFails($bad);
    }

    public function testSchemaRejectsBotnameWithHyphen(): void
    {
        // Pandorabots rejects hyphens; our schema's patternProperties enforces
        // alphanumeric only.
        $bad = (object) [
            'bots' => (object) [
                'my-bot' => (object) ['directory' => './aiml/my-bot'],
            ],
        ];
        $this->assertSchemaFails($bad);
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

    private function assertSchemaAccepts(object $data, string $message = ''): void
    {
        $validator = new Validator();
        $validator->validate($data, (object) ['$ref' => 'file://' . realpath(self::$schemaPath)]);
        $this->assertTrue(
            $validator->isValid(),
            $message ?: sprintf('expected schema to accept config. Errors: %s', $this->formatErrors($validator)),
        );
    }

    private function assertSchemaFails(object $data, string $message = ''): void
    {
        $validator = new Validator();
        $validator->validate($data, (object) ['$ref' => 'file://' . realpath(self::$schemaPath)]);
        $this->assertFalse($validator->isValid(), $message ?: 'expected schema to reject config');
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
