<?php
declare(strict_types=1);

namespace App\Services;

/**
 * AmazonSchemaInspector
 *
 * Consumes the JSON schema (decoded array) from Product Type Definitions.
 * Extracts:
 * - required attributes (directly under attributes.properties)
 * - conditional requirement sets (anyOf / oneOf) under attributes schema
 *
 * This is intentionally "best-effort": Amazon schemas can be deeply nested.
 */
final class AmazonSchemaInspector
{
    /**
     * @return array{
     *   required: string[],
     *   conditional: array<int, array{type:string, required:string[], note:string}>
     * }
     */
    public static function extractAttributeRequirements(array $schema): array
    {
        $attributesSchema = self::findAttributesSchema($schema);

        $required = [];
        if (is_array($attributesSchema)) {
            $required = array_values(array_unique(self::readRequiredList($attributesSchema)));
            sort($required);
        }

        $conditional = [];
        if (is_array($attributesSchema)) {
            $conditional = self::collectConditionalRequired($attributesSchema);
        }

        return [
            'required' => $required,
            'conditional' => $conditional,
        ];
    }

    /**
     * Returns the schema section that describes the "attributes" object.
     */
    public static function findAttributesSchema(array $schema): ?array
    {
        // Common shape: root.properties.attributes
        if (isset($schema['properties']['attributes']) && is_array($schema['properties']['attributes'])) {
            return $schema['properties']['attributes'];
        }

        // Some schemas wrap root in allOf
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $part) {
                if (is_array($part)) {
                    $found = self::findAttributesSchema($part);
                    if ($found !== null) return $found;
                }
            }
        }

        return null;
    }

    /**
     * Required list can sit on the object that has properties (attributes.properties).
     * We also check nested shapes where "attributes" has "properties".
     *
     * @return string[]
     */
    private static function readRequiredList(array $attributesSchema): array
    {
        // Case 1: attributes is object with direct required list for its properties
        if (isset($attributesSchema['required']) && is_array($attributesSchema['required'])) {
            return array_values(array_filter(array_map('strval', $attributesSchema['required'])));
        }

        // Case 2: attributes schema uses allOf and required is in a sub-schema
        if (isset($attributesSchema['allOf']) && is_array($attributesSchema['allOf'])) {
            $acc = [];
            foreach ($attributesSchema['allOf'] as $part) {
                if (is_array($part)) {
                    $acc = array_merge($acc, self::readRequiredList($part));
                }
            }
            return $acc;
        }

        return [];
    }

    /**
     * Collect anyOf/oneOf conditional required sets.
     *
     * @return array<int, array{type:string, required:string[], note:string}>
     */
    private static function collectConditionalRequired(array $schemaNode): array
    {
        $out = [];

        foreach (['anyOf', 'oneOf'] as $key) {
            if (isset($schemaNode[$key]) && is_array($schemaNode[$key])) {
                foreach ($schemaNode[$key] as $idx => $branch) {
                    if (!is_array($branch)) continue;

                    $req = self::readRequiredList($branch);
                    $req = array_values(array_unique(array_map('strval', $req)));
                    sort($req);

                    if ($req) {
                        $out[] = [
                            'type' => $key,
                            'required' => $req,
                            'note' => sprintf('%s branch #%d', $key, $idx + 1),
                        ];
                    }

                    // recurse into branch too (schemas can nest conditionals)
                    $out = array_merge($out, self::collectConditionalRequired($branch));
                }
            }
        }

        // recurse into allOf/properties to find deeper conditionals
        if (isset($schemaNode['allOf']) && is_array($schemaNode['allOf'])) {
            foreach ($schemaNode['allOf'] as $part) {
                if (is_array($part)) {
                    $out = array_merge($out, self::collectConditionalRequired($part));
                }
            }
        }

        if (isset($schemaNode['properties']) && is_array($schemaNode['properties'])) {
            foreach ($schemaNode['properties'] as $prop) {
                if (is_array($prop)) {
                    $out = array_merge($out, self::collectConditionalRequired($prop));
                }
            }
        }

        return $out;
    }

    /**
     * Get attribute property map (attributes.properties).
     *
     * @return array<string, mixed>
     */
    public static function getAttributeProperties(array $schema): array
    {
        $attributesSchema = self::findAttributesSchema($schema);
        if (!is_array($attributesSchema)) return [];

        // Direct
        if (isset($attributesSchema['properties']) && is_array($attributesSchema['properties'])) {
            return $attributesSchema['properties'];
        }

        // allOf merge
        if (isset($attributesSchema['allOf']) && is_array($attributesSchema['allOf'])) {
            $props = [];
            foreach ($attributesSchema['allOf'] as $part) {
                if (is_array($part) && isset($part['properties']) && is_array($part['properties'])) {
                    $props = array_merge($props, $part['properties']);
                }
            }
            return $props;
        }

        return [];
    }
}
