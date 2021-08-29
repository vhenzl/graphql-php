<?php declare(strict_types=1);

namespace GraphQL\Tests\Utils;

use GraphQL\Error\DebugFlag;
use GraphQL\Error\Warning;
use GraphQL\GraphQL;
use GraphQL\Language\Parser;
use GraphQL\Utils\BuildSchema;
use GraphQL\Utils\SchemaExtender;
use PHPUnit\Framework\TestCase;

class SchemaExtenderWithDecoratorTest extends TestCase
{
    // config validation (sanity checks)

    /**
     * TODO: Field config fields: Throw (preferred), ignore, or allow?
     *  Throw for those coming from SDL/AST: name, type, args, description, deprecationReason
     *  Allow: resolve, complexity, custom fields
     */
    public function testDecoratorCannotOverwriteSDLProvidedFieldConfigFields(): void
    {
        $this->expectException('A\Decorator\CannotOverwriteFieldConfigFieldException');
        $documentNode1 = Parser::parse('
            type Query {
                hello: String
            }
        ');

        $typeConfigDecorator1 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Query') {
                $fieldsFn = $typeConfig['fields'];
                $typeConfig['fields'] = static function () use ($fieldsFn): array {
                    $fields = $fieldsFn();
                    $fields['hello']['description'] = 'My description';
                    return $fields;
                };
            }
            return $typeConfig;
        };

        BuildSchema::build($documentNode1, $typeConfigDecorator1);
    }

    /**
     * This also means that a field (resolver) can't be added for an extension
     * ahead of calling extend.
     */
    public function testDecoratorCannotAddUndefinedField(): void
    {
        $this->expectException('A\Decorator\FieldDoesNotExistInSchemaException');
        $documentNode1 = Parser::parse('
            type Query {
                hello: String
            }
        ');

        $typeConfigDecorator1 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Query') {
                $fieldsFn = $typeConfig['fields'];
                $typeConfig['fields'] = static function () use ($fieldsFn): array {
                    $fields = $fieldsFn();
                    self::assertArrayNotHasKey('bye', $fields);
                    $fields['bye'] = [
                        'type' => 'String',
                        'name' => 'bye',
                    ];
                    return $fields;
                };
            }
            return $typeConfig;
        };

        BuildSchema::build($documentNode1, $typeConfigDecorator1);
    }

    /**
     * This also means that an object type config (individual field resolvers, isTypeOf, and resolveField)
     * can't be added for an extension ahead of calling extend.
     */
    public function testDecoratorCannotAddUndefinedType(): void
    {
        $this->expectException('A\Decorator\ObjectTypeDoesNotExistInSchemaException');
        $documentNode1 = Parser::parse('
            type Query {
                hello: String
            }
        ');

        $typeConfigDecorator1 = static function (array $typeConfig): array {
            $typeConfig['MyType'] = [
                'name' => 'MyType',
                'resolveFields' => static fn() => 'Nope!',
            ];
            return $typeConfig;
        };

        BuildSchema::build($documentNode1, $typeConfigDecorator1);
    }

    /**
     * TODO: Object config fields: Throw (preferred), ignore, or allow?
     *  Throw for those coming from SDL/AST: name, description, interfaces
     *  Allow: fields (only to modify existing), isTypeOf, resolveField, custom fields
     */
    public function testDecoratorCannotOverwriteSDLProvidedObjectConfigFields(): void
    {
        $this->expectException('A\Decorator\CannotOverwriteObjectConfigFieldException');
        $documentNode1 = Parser::parse('
            type Query {
                hello: String
            }
        ');

        $typeConfigDecorator1 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Query') {
                $typeConfig['description'] = 'My description';
            }
            return $typeConfig;
        };

        BuildSchema::build($documentNode1, $typeConfigDecorator1);
    }

    // individual field resolvers and type level resolvers

    public function testDecoratorAddsIndividualFieldResolversInEachExtend(): void
    {
        $documentNode1 = Parser::parse('
            type Query {
                hello: String
            }
        ');

        $typeConfigDecorator1 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Query') {
                $fieldsFn = $typeConfig['fields'];
                $typeConfig['fields'] = static function () use ($fieldsFn): array {
                    // TODO: assert callable?
                    $fields = $fieldsFn();
                    $fields['hello']['resolve'] = static fn(): string => 'Hey!';
                    return $fields;
                };
            }
            return $typeConfig;
        };

        $schema1 = BuildSchema::build($documentNode1, $typeConfigDecorator1);

        $documentNode2 = Parser::parse('
            extend type Query {
                bye: String
            }
        ');

        $typeConfigDecorator2 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Query') {
                $fieldsFn = $typeConfig['fields'];
                $typeConfig['fields'] = static function () use ($fieldsFn): array {
                    // TODO: assert callable?
                    $fields = $fieldsFn();
                    $fields['bye']['resolve'] = static fn(): string => 'See ya!';
                    return $fields;
                };
            }
            return $typeConfig;
        };

        $schema2 = SchemaExtender::extend($schema1, $documentNode2, [], $typeConfigDecorator2);

        $documentNode3 = Parser::parse('
              extend type Query {
                thanks: String
              }
        ');

        $typeConfigDecorator3 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Query') {
                $fieldsFn = $typeConfig['fields'];
                $typeConfig['fields'] = static function () use ($fieldsFn): array {
                    // TODO: assert callable?
                    $fields = $fieldsFn();
                    $fields['bye']['thanks'] = static fn(): string => 'Cheers!';
                    return $fields;
                };
            }
            return $typeConfig;
        };

        $schema3 = SchemaExtender::extend($schema2, $documentNode3, [], $typeConfigDecorator3);

        $query = '{ 
            hello
            bye
            thanks
        }';

        $result = GraphQL::executeQuery($schema3, $query);

        self::assertSame(['data' => ['hello' => 'Hey!', 'bye' => 'See ya!', 'thanks' => 'Cheers!']], $result->toArray());
    }

    public function testDecoratorAddsAllIndividualFieldResolversInTheLastExtend(): void
    {
        $documentNode1 = Parser::parse('
            type Query {
                hello: String
            }
        ');

        $schema1 = BuildSchema::build($documentNode1);

        $documentNode2 = Parser::parse('
            extend type Query {
                bye: String
            }
        ');

        $schema2 = SchemaExtender::extend($schema1, $documentNode2);

        $documentNode3 = Parser::parse('
            extend type Query {
                thanks: String
            }
        ');

        $typeConfigDecorator = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Query') {
                $fieldsFn = $typeConfig['fields'];
                $typeConfig['fields'] = static function () use ($fieldsFn): array {
                    // TODO: assert callable?
                    $fields = $fieldsFn();
                    $fields['hello']['resolve'] = static fn(): string => 'Hey!';
                    $fields['bye']['resolve'] = static fn(): string => 'See ya!';
                    $fields['bye']['thanks'] = static fn(): string => 'Cheers!';
                    return $fields;
                };
            }
            return $typeConfig;
        };

        $schema3 = SchemaExtender::extend($schema2, $documentNode3, [], $typeConfigDecorator);

        $query = '{ 
            hello
            bye
            thanks
        }';

        $result = GraphQL::executeQuery($schema3, $query);

        self::assertSame(['data' => ['hello' => 'Hey!', 'bye' => 'See ya!', 'thanks' => 'Cheers!']], $result->toArray());
    }

    /**
     * TODO: What the logic for overwriting should be? There are there option:
     *  - throw error if a resolver already exists (preferred)
     *  - silently ignore the resolver if one already exists
     *  - allow overwriting
     */
    public function testDecoratorCannotOverwriteExistingIndividualFieldResolvers(): void
    {
        $this->expectException('A\Decorator\FieldResolverAlreadyExistsException');
        $documentNode1 = Parser::parse('
            type Query {
                hello: String
            }
        ');

        $typeConfigDecorator1 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Query') {
                $fieldsFn = $typeConfig['fields'];
                $typeConfig['fields'] = static function () use ($fieldsFn): array {
                    $fields = $fieldsFn();
                    $fields['hello']['resolve'] = static fn(): string => 'Hey!';
                    return $fields;
                };
            }
            return $typeConfig;
        };

        $schema1 = BuildSchema::build($documentNode1, $typeConfigDecorator1);

        $documentNode2 = Parser::parse('
            extend type Query {
                bye: String
            }
        ');

        $typeConfigDecorator2 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Query') {
                $fieldsFn = $typeConfig['fields'];
                $typeConfig['fields'] = static function () use ($fieldsFn): array {
                    $fields = $fieldsFn();
                    $fields['hello']['resolve'] = static fn(): string => 'Hello!';
                    $fields['bye']['resolve'] = static fn(): string => 'Bye!';
                    return $fields;
                };
            }
            return $typeConfig;
        };

        SchemaExtender::extend($schema1, $documentNode2, [], $typeConfigDecorator2);
    }

    public function testLaterTypeLevelResolverOverwritesThePreviousOne(): void
    {
        $documentNode1 = Parser::parse('
            type Query {
                hello: String
            }
        ');

        $typeConfigDecorator1 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Query') {
                $typeConfig['resolveField'] = static function ($source, $args, $context, $info) {
                    if ($info->fieldName === 'hello') {
                        return 'Hey!';
                    }
                    return null;
                };
            }
            return $typeConfig;
        };

        $schema1 = BuildSchema::build($documentNode1, $typeConfigDecorator1);

        $documentNode2 = Parser::parse('
            extend type Query {
                bye: String
            }
        ');

        $typeConfigDecorator2 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Query') {
                $typeConfig['resolveField'] = static function ($source, $args, $context, $info) {
                    if ($info->fieldName === 'bye') {
                        return 'See ya!';
                    }
                    return null;
                };
            }
            return $typeConfig;
        };

        $schema2 = SchemaExtender::extend($schema1, $documentNode2, [], $typeConfigDecorator2);

        $query = '{ 
            hello
            bye
        }';

        $result = GraphQL::executeQuery($schema2, $query, null, null, null, null, fn() => '*default*');

        self::assertSame(['data' => ['hello' => '*default*', 'bye' => 'See ya!']], $result->toArray());
    }

    public function testLaterTypeLevelResolverCanUseThePreviousOne(): void
    {
        $documentNode1 = Parser::parse('
            type Query {
                hello: String
            }
        ');

        $typeConfigDecorator1 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Query') {
                $typeConfig['resolveField'] = static function ($source, $args, $context, $info) {
                    if ($info->fieldName === 'hello') {
                        return 'Hey!';
                    }
                    return null;
                };
            }
            return $typeConfig;
        };

        $schema1 = BuildSchema::build($documentNode1, $typeConfigDecorator1);

        $documentNode2 = Parser::parse('
            extend type Query {
                bye: String
            }
        ');

        $typeConfigDecorator2 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Query') {
                $resolveFieldFn = $typeConfig['resolveField'];
                $typeConfig['resolveField'] = static function ($source, $args, $context, $info) use ($resolveFieldFn) {
                    // first handle the fields added by this extension
                    if ($info->fieldName === 'bye') {
                        return 'See ya!';
                    }
                    // then let the existing resolver handle the original fields
                    return $resolveFieldFn($source, $args, $context, $info);
                };
            }
            return $typeConfig;
        };

        $schema2 = SchemaExtender::extend($schema1, $documentNode2, [], $typeConfigDecorator2);

        $query = '{ 
            hello
            bye
        }';

        $result = GraphQL::executeQuery($schema2, $query, null, null, null, null, fn() => '*default*');

        self::assertSame(['data' => ['hello' => 'Hey!', 'bye' => 'See ya!']], $result->toArray());
    }

    public function testIndividualFieldResolversHasPrecedenceOverTypeLevelResolverRegardlessOrder(): void
    {
        $documentNode1 = Parser::parse('
            type Query {
                hello: String
            }
        ');

        $typeConfigDecorator1 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Query') {
                $typeConfig['resolveField'] = static fn() => '*query*';
            }
            return $typeConfig;
        };

        $schema1 = BuildSchema::build($documentNode1, $typeConfigDecorator1);

        $documentNode2 = Parser::parse('
            extend type Query {
                bye: String
            }
        ');

        $typeConfigDecorator2 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Query') {
                $fieldsFn = $typeConfig['fields'];
                $typeConfig['fields'] = static function () use ($fieldsFn): array {
                    $fields = $fieldsFn();
                    $fields['hello']['resolve'] = static fn(): string => 'Hello!';
                    $fields['bye']['resolve'] = static fn(): string => 'Bye!';
                    return $fields;
                };
            }
            return $typeConfig;
        };

        $schema2 = SchemaExtender::extend($schema1, $documentNode2, [], $typeConfigDecorator2);

        $query = '{ 
            hello
            bye
        }';

        $result = GraphQL::executeQuery($schema2, $query);

        self::assertSame(['data' => ['hello' => 'Hello!', 'bye' => 'Bye!']], $result->toArray());
    }

    // isTypeOf (for interface)

    public function testDecoratorAddIsTypeOfInEachExtend(): void
    {
        $documentNode1 = Parser::parse('
            interface Character {
                name: String
            }
            type Human implements Character {
                name: String
                homePlanet: String
            }
            type Query {
                characters: [Character]
            }
        ');

        $typeConfigDecorator1 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Human') {
                $typeConfig['isTypeOf'] = static fn($value) => is_array($value) && array_key_exists('homePlanet', $value);
            }
            return $typeConfig;
        };

        $schema1 = BuildSchema::build($documentNode1, $typeConfigDecorator1);

        $documentNode2 = Parser::parse('
            type Droid implements Character {
                name: String
                primaryFunction: String
            }
        ');

        $typeConfigDecorator2 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Droid') {
                $typeConfig['isTypeOf'] = static fn($value) => is_array($value) && array_key_exists('primaryFunction', $value);
            }
            return $typeConfig;
        };

        $schema2 = SchemaExtender::extend($schema1, $documentNode2, [], $typeConfigDecorator2);

        $query = '{
          characters {
            name
              ... on Human {
              homePlanet
            }
              ... on Droid {
              primaryFunction
            }
          }
        }';

        $rootValue = [
            'characters' => [
                ['name' => 'Luke Skywalker', 'homePlanet' => 'Tatooine'],
                ['name' => 'R2-D2', 'primaryFunction' => 'Astromech'],
            ],
        ];

        // TODO: Why the execution doesn't trigger the full schema scan warning here?
        $result = GraphQL::executeQuery($schema2, $query, $rootValue);

        self::assertSame(
            ['data' => [
                'characters' => [
                    ['name' => 'Luke Skywalker', 'homePlanet' => 'Tatooine'],
                    ['name' => 'R2-D2', 'primaryFunction' => 'Astromech'],
                ]
            ]],
            $result->toArray(),
        );
    }

    public function testDecoratorAddAllIsTypeOfInTheLastExtend(): void
    {
        $documentNode1 = Parser::parse('
            interface Character {
                name: String
            }
            type Human implements Character {
                name: String
                homePlanet: String
            }
            type Query {
                characters: [Character]
            }
        ');

        $schema1 = BuildSchema::build($documentNode1);

        $documentNode2 = Parser::parse('
            type Droid implements Character {
                name: String
                primaryFunction: String
            }
        ');

        $typeConfigDecorator2 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Human') {
                $typeConfig['isTypeOf'] = static fn($value) => is_array($value) && array_key_exists('homePlanet', $value);
            }
            if ($typeConfig['name'] === 'Droid') {
                $typeConfig['isTypeOf'] = static fn($value) => is_array($value) && array_key_exists('primaryFunction', $value);
            }
            return $typeConfig;
        };

        $schema2 = SchemaExtender::extend($schema1, $documentNode2, [], $typeConfigDecorator2);

        $query = '{
          characters {
            name
              ... on Human {
              homePlanet
            }
              ... on Droid {
              primaryFunction
            }
          }
        }';

        $rootValue = [
            'characters' => [
                ['name' => 'Luke Skywalker', 'homePlanet' => 'Tatooine'],
                ['name' => 'R2-D2', 'primaryFunction' => 'Astromech'],
            ],
        ];

        // suppress the warning so that isTypeOf is actually used during the execution
        Warning::suppress(Warning::WARNING_FULL_SCHEMA_SCAN);
        $result = GraphQL::executeQuery($schema2, $query, $rootValue);
        Warning::enable(Warning::WARNING_FULL_SCHEMA_SCAN);

        self::assertSame(
            ['data' => [
                'characters' => [
                    ['name' => 'Luke Skywalker', 'homePlanet' => 'Tatooine'],
                    ['name' => 'R2-D2', 'primaryFunction' => 'Astromech'],
                ]
            ]],
            $result->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE),
        );
    }

    /**
     * TODO: can or cannot?
     */
    public function testDecoratorCanOverwriteExistingIsTypeOf(): void
    {
        // $this->expectException('A\Decorator\IsTypeOfAlreadyExistsException');
        $documentNode1 = Parser::parse('
            interface Character {
                name: String
            }
            type Human implements Character {
                name: String
                homePlanet: String
            }
            type Query {
                characters: [Character]
            }
        ');

        $typeConfigDecorator1 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Human') {
                $typeConfig['isTypeOf'] = static fn($value) => is_array($value) && array_key_exists('homePlanet', $value);
            }
            return $typeConfig;
        };

        $schema1 = BuildSchema::build($documentNode1, $typeConfigDecorator1);

        $documentNode2 = Parser::parse('
            type Droid implements Character {
                name: String
                primaryFunction: String
            }
        ');

        $typeConfigDecorator2 = static function (array $typeConfig): array {
            // TODO: What would be a valid, real-world use-case for overwriting isTypeOf?
            // changing human to droid and droid to human here
            if ($typeConfig['name'] === 'Human') {
                $typeConfig['isTypeOf'] = static fn($value) => is_array($value) && array_key_exists('primaryFunction', $value);
            }
            if ($typeConfig['name'] === 'Droid') {
                $typeConfig['isTypeOf'] = static fn($value) => is_array($value) && array_key_exists('homePlanet', $value);
            }
            return $typeConfig;
        };

        $schema2 = SchemaExtender::extend($schema1, $documentNode2, [], $typeConfigDecorator2);

        $query = '{
          characters {
            name
              ... on Human {
              homePlanet
            }
              ... on Droid {
              primaryFunction
            }
          }
        }';

        $rootValue = [
            'characters' => [
                ['name' => 'Luke Skywalker', 'homePlanet' => 'Tatooine'],
                ['name' => 'R2-D2', 'primaryFunction' => 'Astromech'],
            ],
        ];

        // suppress the warning so that isTypeOf is actually used during the execution
        Warning::suppress(Warning::WARNING_FULL_SCHEMA_SCAN);
        $result = GraphQL::executeQuery($schema2, $query, $rootValue);
        Warning::enable(Warning::WARNING_FULL_SCHEMA_SCAN);

        self::assertSame(
            ['data' => [
                'characters' => [
                    ['name' => 'Luke Skywalker', 'primaryFunction' => null],
                    ['name' => 'R2-D2', 'homePlanet' => null],
                ]
            ]],
            $result->toArray(),
        );
    }

    // resolveType (interface)

    public function testInterfaceLaterResolveTypeOverwritesThePreviousOne(): void
    {
        $documentNode1 = Parser::parse('
            interface Character {
                name: String
            }
            type Human implements Character {
                name: String
                homePlanet: String
            }
            type Query {
                characters: [Character]
            }
        ');

        $typeConfigDecorator1 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Character') {
                $typeConfig['resolveType'] = static function ($value): ?string {
                    if (!is_array($value)) return null;
                    if (array_key_exists('homePlanet', $value)) return 'Human';
                    return null;
                };
            }
            return $typeConfig;
        };

        $schema1 = BuildSchema::build($documentNode1, $typeConfigDecorator1);

        $documentNode2 = Parser::parse('
            type Droid implements Character {
                name: String
                primaryFunction: String
            }
        ');

        $typeConfigDecorator2 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Character') {
                $typeConfig['resolveType'] = static function ($value): ?string {
                    if (!is_array($value)) return null;
                    if (array_key_exists('primaryFunction', $value)) return 'Droid';
                    return null;
                };
            }
            return $typeConfig;
        };

        $schema2 = SchemaExtender::extend($schema1, $documentNode2, [], $typeConfigDecorator2);

        $query = '{
          characters {
            name
              ... on Human {
              homePlanet
            }
              ... on Droid {
              primaryFunction
            }
          }
        }';

        $rootValue = [
            'characters' => [
                ['name' => 'Luke Skywalker', 'homePlanet' => 'Tatooine'],
                ['name' => 'R2-D2', 'primaryFunction' => 'Astromech'],
            ],
        ];

        $result = GraphQL::executeQuery($schema2, $query, $rootValue);

        self::assertSame(
            ['data' => [
                'characters' => [
                    ['name' => 'Luke Skywalker', 'homePlanet' => 'Tatooine'],
                    ['name' => 'R2-D2', 'primaryFunction' => 'Astromech'],
                ]
            ]],
            $result->toArray(),
        );
    }

    public function testInterfaceLaterResolveTypeCanUseThePreviousOne(): void
    {
        $documentNode1 = Parser::parse('
            interface Character {
                name: String
            }
            type Human implements Character {
                name: String
                homePlanet: String
            }
            type Query {
                characters: [Character]
            }
        ');

        $typeConfigDecorator1 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Character') {
                $typeConfig['resolveType'] = static function ($value): ?string {
                    if (!is_array($value)) return null;
                    if (array_key_exists('homePlanet', $value)) return 'Human';
                    return null;
                };
            }
            return $typeConfig;
        };

        $schema1 = BuildSchema::build($documentNode1, $typeConfigDecorator1);

        $documentNode2 = Parser::parse('
            type Droid implements Character {
                name: String
                primaryFunction: String
            }
        ');

        $typeConfigDecorator2 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Character') {
                $resolveTypeFn = $typeConfig['resolveType'];
                $typeConfig['resolveType'] = static function ($value, $context, $info) use ($resolveTypeFn): ?string {
                    // first handle the types added by this extension
                    if (is_array($value) && array_key_exists('primaryFunction', $value)) return 'Droid';
                    // then let the existing type resolver handle the other types
                    return $resolveTypeFn($value, $context, $info);
                };
            }
            return $typeConfig;
        };

        $schema2 = SchemaExtender::extend($schema1, $documentNode2, [], $typeConfigDecorator2);

        $query = '{
          characters {
            name
              ... on Human {
              homePlanet
            }
              ... on Droid {
              primaryFunction
            }
          }
        }';

        $rootValue = [
            'characters' => [
                ['name' => 'Luke Skywalker', 'homePlanet' => 'Tatooine'],
                ['name' => 'R2-D2', 'primaryFunction' => 'Astromech'],
            ],
        ];

        Warning::suppress(Warning::WARNING_FULL_SCHEMA_SCAN);
        $result = GraphQL::executeQuery($schema2, $query, $rootValue);
        Warning::enable(Warning::WARNING_FULL_SCHEMA_SCAN);

        self::assertSame([
            'errors' => [[
                'message' => 'Internal server error',
                'locations' => [['line' => 2, 'column' => 11]],
                'path' => ['characters', 0],
                'extensions' => [
                    'debugMessage' => 'Abstract type Character must resolve to an Object type at runtime for field Query.characters with value "{"name":"Luke Skywalker","homePlanet":"Tatooine"}", received "null". Either the Character type should provide a "resolveType" function or each possible type should provide an "isTypeOf" function.'
                ],
            ]],
            'data' => [
                'characters' => [
                    null,
                    ['name' => 'R2-D2', 'primaryFunction' => 'Astromech'],
                ],
            ]],
            $result->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE),
        );
    }

}
