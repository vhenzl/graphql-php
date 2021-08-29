<?php declare(strict_types=1);

namespace GraphQL\Tests\Utils;

use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use GraphQL\Language\Parser;
use GraphQL\Utils\BuildSchema;
use GraphQL\Utils\SchemaExtender;
use PHPUnit\Framework\TestCase;

class SchemaExtenderWithDecoratorTest extends TestCase
{

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


    public function testDecoratorLaterTypeLevelResolverOverwritesThePreviousOne(): void
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

    public function testDecoratorLaterTypeLevelResolverCanUseThePreviousOne(): void
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

    public function testDecoratorIndividualFieldResolversHasPrecedenceOverTypeLevelResolverRegardlessOrder(): void
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


    /**
     * based on @see it('isTypeOf used to resolve runtime type for Interface'
     */
    public function testInterface1a(): void
    {
        $documentNode1 = Parser::parse('
            interface Pet {
                name: String
            }
            type Dog implements Pet {
                name: String
                woofs: Boolean
            }
            type Query {
                pets: [Pet]
            }
        ');

        $typeConfigDecorator1 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Dog') {
                $typeConfig['isTypeOf'] = static fn($value) => is_array($value) && array_key_exists('woofs', $value);
            }
            return $typeConfig;
        };

        $schema1 = BuildSchema::build($documentNode1, $typeConfigDecorator1);

        $documentNode2 = Parser::parse('
            type Cat implements Pet {
                name: String
                meows: Boolean
            }
        ');

        $typeConfigDecorator2 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Cat') {
                $typeConfig['isTypeOf'] = static fn($value) => is_array($value) && array_key_exists('meows', $value);
            }
            return $typeConfig;
        };

        $schema2 = SchemaExtender::extend($schema1, $documentNode2, [], $typeConfigDecorator2);

        $query = '{
          pets {
            name
              ... on Dog {
              woofs
            }
              ... on Cat {
              meows
            }
          }
        }';

        $rootValue = [
            'pets' => [
                ['name' => 'Odie', 'woofs' => true],
                ['name' => 'Garfield', 'meows' => false],
            ],
        ];

        $result = GraphQL::executeQuery($schema2, $query, $rootValue);

        self::assertSame(
            ['data' => [
                'pets' => [
                    ['name' => 'Odie', 'woofs' => true],
                    ['name' => 'Garfield', 'meows' => false],
                ]
            ]],
            $result->toArray(),
        );
    }


    public function testInterface1LaterResolveTypeOverwritesThePreviousOne(): void
    {
        $documentNode1 = Parser::parse('
            interface Pet {
                name: String
            }
            type Dog implements Pet {
                name: String
                woofs: Boolean
            }
            type Query {
                pets: [Pet]
            }
        ');

        $typeConfigDecorator1 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Pet') {
                $typeConfig['resolveType'] = static function ($value): ?string {
                    if (!is_array($value)) return null;
                    if (array_key_exists('woofs', $value)) return 'Dog';
                    return null;
                };
            }
            return $typeConfig;
        };

        $schema1 = BuildSchema::build($documentNode1, $typeConfigDecorator1);

        $documentNode2 = Parser::parse('
            type Cat implements Pet {
                name: String
                meows: Boolean
            }
        ');

        $typeConfigDecorator2 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Pet') {
                $typeConfig['resolveType'] = static function ($value): ?string {
                    if (!is_array($value)) return null;
                    if (array_key_exists('meows', $value)) return 'Cat';
                    return null;
                };
            }
            return $typeConfig;
        };

        $schema2 = SchemaExtender::extend($schema1, $documentNode2, [], $typeConfigDecorator2);

        $query = '{
          pets {
            name
              ... on Dog {
              woofs
            }
              ... on Cat {
              meows
            }
          }
        }';

        $rootValue = [
            'pets' => [
                ['name' => 'Odie', 'woofs' => true],
                ['name' => 'Garfield', 'meows' => false],
            ],
        ];

        $result = GraphQL::executeQuery($schema2, $query, $rootValue);

        self::assertSame(
            ['data' => [
                'pets' => [
                    ['name' => 'Odie', 'woofs' => true],
                    ['name' => 'Garfield', 'meows' => false],
                ]
            ]],
            $result->toArray(),
        );
    }

    public function testInterface1LaterResolveTypeCanUseThePreviousOne(): void
    {
        $documentNode1 = Parser::parse('
            interface Pet {
                name: String
            }
            type Dog implements Pet {
                name: String
                woofs: Boolean
            }
            type Query {
                pets: [Pet]
            }
        ');

        $typeConfigDecorator1 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Pet') {
                $typeConfig['resolveType'] = static function ($value): ?string {
                    if (!is_array($value)) return null;
                    if (array_key_exists('woofs', $value)) return 'Dog';
                    return null;
                };
            }
            return $typeConfig;
        };

        $schema1 = BuildSchema::build($documentNode1, $typeConfigDecorator1);

        $documentNode2 = Parser::parse('
            type Cat implements Pet {
                name: String
                meows: Boolean
            }
        ');

        $typeConfigDecorator2 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Pet') {
                $resolveTypeFn = $typeConfig['resolveType'];
                $typeConfig['resolveType'] = static function ($value, $context, $info) use ($resolveTypeFn): ?string {
                    // first handle the types added by this extension
                    if (is_array($value) && array_key_exists('meows', $value)) return 'Cat';
                    // then let the existing type resolver handle the other types
                    return $resolveTypeFn($value, $context, $info);
                };
            }
            return $typeConfig;
        };

        $schema2 = SchemaExtender::extend($schema1, $documentNode2, [], $typeConfigDecorator2);

        $query = '{
          pets {
            name
              ... on Dog {
              woofs
            }
              ... on Cat {
              meows
            }
          }
        }';

        $rootValue = [
            'pets' => [
                ['name' => 'Odie', 'woofs' => true],
                ['name' => 'Garfield', 'meows' => false],
            ],
        ];

        $result = GraphQL::executeQuery($schema2, $query, $rootValue);

        self::assertSame([
            'errors' => [[
                'message' => 'Internal server error',
                'locations' => [['line' => 2, 'column' => 11]],
                'path' => ['pets', 0],
                'extensions' => [
                    'debugMessage' => 'GraphQL Interface Type `Pet` returned `null` from its `resolveType` function for value: {"name":"Odie","woofs":true}. Switching to slow resolution method using `isTypeOf` of all possible implementations. It requires full schema scan and degrades query performance significantly.  Make sure your `resolveType` always returns valid implementation or throws.'
                ],
            ]],
            'data' => [
                'pets' => [
                    null,
                    ['name' => 'Garfield', 'meows' => false],
                ],
            ]],
            $result->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE),
        );
    }

    public function testXXX1(): void
    {
        $documentNode1 = Parser::parse('
            interface Pet {
                name: String
            }
            type Dog implements Pet {
                name: String
                woofs: Boolean
            }
            type Query {
                pets: [Pet]
            }
            type Cat implements Pet {
                name: String
                meows: Boolean
            }
        ');

        $typeConfigDecorator2 = static function (array $typeConfig): array {
            if ($typeConfig['name'] === 'Pet') {
                $resolveTypeFn = $typeConfig['resolveType'] ?? static fn() => null;
                $typeConfig['resolveType'] = static function ($value, $context, $info) use ($resolveTypeFn): ?string {
                    // first handle the types added by this extension
                    if (is_array($value) && array_key_exists('meows', $value)) return 'Cat';
                    // then let the existing type resolver handle the other types
                    return $resolveTypeFn($value, $context, $info);
                };
            }
            return $typeConfig;
        };

        $schema2 = BuildSchema::build($documentNode1, $typeConfigDecorator2);

        $query = '{
          pets {
            name
              ... on Dog {
              woofs
            }
              ... on Cat {
              meows
            }
          }
        }';

        $rootValue = [
            'pets' => [
                ['name' => 'Odie', 'woofs' => true],
                ['name' => 'Garfield', 'meows' => false],
            ],
        ];

        $result = GraphQL::executeQuery($schema2, $query, $rootValue);

        self::assertSame([
            'errors' => [
                [
                    'message' => 'Internal server error',
                    'locations' => [['line' => 2, 'column' => 11]],
                    'path' => ['pets', 0],
                    'extensions' => [
                        'debugMessage' => 'GraphQL Interface Type `Pet` returned `null` from its `resolveType` function for value: {"name":"Odie","woofs":true}. Switching to slow resolution method using `isTypeOf` of all possible implementations. It requires full schema scan and degrades query performance significantly.  Make sure your `resolveType` always returns valid implementation or throws.'
                    ],

                ]
            ],
            'data' => [
                'pets' => [
                    null,
                    ['name' => 'Garfield', 'meows' => false],
                ]
            ]],
            $result->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE),
        );
    }

    /**
     * @see it('extends objects by adding implemented interfaces'
     */
    public function testInterface2(): void
    {
        $documentNode1 = Parser::parse('
            type Query {
                someObject: SomeObject
            }            
            type SomeObject {
                foo: String
            }            
            interface SomeInterface {
                foo: String
            }
        ');
        $documentNode2 = Parser::parse('
            extend type SomeObject implements SomeInterface
        ');
        $query = '{
          someObject {
            foo
          }
        }';
    }

    /**
     * @see it('extends objects by adding implemented new interfaces'
     */
    public function testInterface3(): void
    {
        $documentNode1 = Parser::parse('
            type Query {
                someObject: SomeObject
            }            
            type SomeObject implements OldInterface {
                oldField: String
            }            
            interface OldInterface {
                oldField: String
            }
        ');
        $documentNode2 = Parser::parse('
            extend type SomeObject implements NewInterface {
                newField: String
            }            
            interface NewInterface {
                newField: String
            }
        ');
        $query = '{
          someObject {
            oldField
            newField
          }
        }';
    }

    /**
     * @see it('extends interfaces by adding new implemented interfaces'
     */
    public function testInterface4(): void
    {
        $documentNode1 = Parser::parse('
      interface SomeInterface {
        oldField: String
      }

      interface AnotherInterface implements SomeInterface {
        oldField: String
      }

      type SomeObject implements SomeInterface & AnotherInterface {
        oldField: String
      }

      type Query {
        someInterface: SomeInterface
      }
        ');
        $documentNode2 = Parser::parse('
            extend type SomeObject implements NewInterface {
                newField: String
            }            
            interface NewInterface {
                newField: String
            }
        ');
        $query = '{
          someObject {
            oldField
            newField
          }
        }';
    }
}