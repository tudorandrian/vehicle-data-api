<?php

declare(strict_types=1);

namespace VehicleData\Core\Kinds;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use stdClass;

final class SchemaValidator
{
    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $jsonSchema
     * @return list<string>
     */
    public static function errors(array $data, array $jsonSchema): array
    {
        $result = (new Validator)->validate(
            json_decode((string) json_encode($data === [] ? new stdClass : $data)),
            json_decode((string) json_encode($jsonSchema)),
        );
        if ($result->isValid()) {
            return [];
        }
        $error = $result->error();
        if ($error === null) {
            return [];
        }

        $messages = [];
        foreach ((new ErrorFormatter)->formatFlat($error) as $message) {
            $messages[] = (string) $message;
        }

        return $messages;
    }
}
