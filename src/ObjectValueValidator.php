<?php
namespace Terrazza\Component\Validator;
use DateTime;
use RuntimeException;
use Terrazza\Component\Validator\Exception\InvalidObjectValueArgumentException;
use Throwable;

class ObjectValueValidator implements ObjectValueValidatorInterface {
    CONST boolean_values = ["true", "false", "1", "0", "yes", "no", 1, 0];
    /**
     * @param $content
     * @param ObjectValueSchema $contentSchema
     * @param string|null $parentPropertyName
     * @return bool
     */
    public function isValidSchema($content, ObjectValueSchema $contentSchema, ?string $parentPropertyName=null) : bool {
        try {
            $this->validateSchema($content, $contentSchema, $parentPropertyName);
            return true;
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * @param $content
     * @param ObjectValueSchema $contentSchema
     * @param string|null $parentPropertyName
     */
    public function validateSchema($content, ObjectValueSchema $contentSchema, ?string $parentPropertyName=null) : void {
        switch ($contentSchema->getType()) {
            case "object":
                if ($contentSchema->hasChildSchemas()) {
                    $this->validateSchemas($content, $contentSchema->getChildSchemas(), $contentSchema->getName());
                } else {
                    throw new RuntimeException($contentSchema->getName()." is object and has no properties");
                }
                break;
            default:
                try {
                    $this->validateContentType($content, $contentSchema->isNullable(), $contentSchema->getType());
                    $this->validateArray($content, $contentSchema->getMinItems(), $contentSchema->getMaxItems());
                    $this->validateString($content, $contentSchema->getMinLength(), $contentSchema->getMaxLength(), $contentSchema->getPatterns());
                    $this->validateNumber($content, $contentSchema->getMinRange(), $contentSchema->getMaxRange(), $contentSchema->getMultipleOf());
                    $this->validateFormat($content, $contentSchema->getFormat());
                } catch (InvalidObjectValueArgumentException $exception) {
                    $argumentName                   = $contentSchema->getName();
                    $fullPropertyName               = $parentPropertyName ? $parentPropertyName.".".$argumentName : $argumentName;
                    throw new InvalidObjectValueArgumentException("argument $fullPropertyName invalid: ".$exception->getMessage());
                }
        }
    }

    /**
     * @param $content
     * @param array $contentSchema
     * @param string|null $parentPropertyName
     * @return bool
     */
    public function isValidSchemas($content, array $contentSchema, ?string $parentPropertyName=null) : bool {
        try {
            $this->validateSchemas($content, $contentSchema, $parentPropertyName);
            return true;
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * @param $content
     * @param array|ObjectValueSchema[] $contentSchema
     * @param string|null $parentPropertyName
     * @return void
     */
    public function validateSchemas($content, array $contentSchema, ?string $parentPropertyName=null) : void {
        $content                                    = (array)$content;
        foreach ($contentSchema as $inputSchema) {
            $propertyName                           = $inputSchema->getName();
            $fullPropertyName                       = $parentPropertyName ? $parentPropertyName.".".$propertyName : $propertyName;
            $inputExists                            = array_key_exists($propertyName, $content);
            if (!$inputSchema->isOptional() && !$inputExists) {
                throw new InvalidObjectValueArgumentException("argument $fullPropertyName required, missing");
            }
            if ($inputExists) {
                $inputValue                         = $content[$propertyName];
                $this->validateSchema($inputValue, $inputSchema,$fullPropertyName);
                unset($content[$propertyName]);
            }
        }
        $unmappedKeys                               = [];
        foreach ($content as $cKey => $cValue) {
            $unmappedKeys[]                         = $parentPropertyName ? $parentPropertyName.".".$cKey : $cKey;
        }
        if (count($unmappedKeys)) {
            $arguments                              = "argument".(count($unmappedKeys) > 1 ? "s" : "");
            throw new InvalidObjectValueArgumentException("$arguments (".join(", ", $unmappedKeys).") not allowed");
        }
    }

    /**
     * @param $content
     * @param bool $nullable
     * @param string|null $expectedType
     */
    private function validateContentType($content, bool $nullable, ?string $expectedType) : void {
        if (!$expectedType) {
            throw new RuntimeException("no type to be validated given");
        }
        if (is_null($content)) {
            if ($nullable) {
                return;
            } else {
                throw new InvalidObjectValueArgumentException("value expected, given null");
            }
        }
        $inputType                                  = gettype($content);
        if ($inputType === $expectedType) return;
        if ($expectedType === "number") {
            if ($inputType === "integer" || $inputType === "double") {
                return;
            }
            if ($inputType === "string" && strval(intval($content)) === $content) {
                return;
            }
        }
        if ($expectedType === "boolean") {
            if ($inputType === "string" && in_array(strtolower($content), self::boolean_values, true)) {
                return;
            }
            elseif ($inputType === "integer" && in_array($content, self::boolean_values, true)) {
                return;
            } else {
                throw new InvalidObjectValueArgumentException("type $expectedType expected (".join(",", array_unique(self::boolean_values))."), given $inputType");
            }
        }
        throw new InvalidObjectValueArgumentException("type $expectedType expected, given $inputType");
    }

    /**
     * @param $content
     * @param int|null $minLength
     * @param int|null $maxLength
     * @param string|null $pattern
     */
    private function validateString($content, ?int $minLength, ?int $maxLength, ?string $pattern) : void {
        if (!is_scalar($content)) return;
        if ($minLength && strlen($content) < $minLength) {
            throw new InvalidObjectValueArgumentException("min length $minLength expected, given length ".strlen($content));
        }
        if ($maxLength && strlen($content) > $maxLength) {
            throw new InvalidObjectValueArgumentException("max length $maxLength expected, given length ".strlen($content));
        }
        if ($pattern) {
            if (!preg_match("#$pattern#", $content)) {
                throw new InvalidObjectValueArgumentException("pattern $pattern does not match, given $content");
            }
        }
    }

    /**
     * @param $content
     * @param string|null $format
     */
    private function validateFormat($content, ?string $format): void {
        if (!is_scalar($content)) return;
        switch ($format) {
            case "date":
                $dFormat                            = "Y-m-d";
                $cDate                              = DateTime::createFromFormat($dFormat, $content);
                if (!$cDate || $cDate->format($dFormat) !== $content) {
                    throw new InvalidObjectValueArgumentException("valid date expected, given $content");
                }
                break;
            case "email":
                if (filter_var($content, FILTER_VALIDATE_EMAIL)) {
                    return;
                }
                throw new InvalidObjectValueArgumentException("valid email expected, given $content");
        }
    }

    /**
     * @param $content
     * @param int|null $minItems
     * @param int|null $maxItems
     */
    private function validateArray($content, ?int $minItems, ?int $maxItems) : void {
        if (!is_array($content)) {
            return;
        }
        if ($minItems && count($content) < $minItems) {
            throw new InvalidObjectValueArgumentException("min items $minItems expected, given items ".count($content));
        }
        if ($maxItems && count($content) > $maxItems) {
            throw new InvalidObjectValueArgumentException("max items $maxItems expected, given items ".count($content));
        }
    }

    /**
     * @param $content
     * @param float|null $minRange
     * @param float|null $maxRange
     * @param int|null $multipleOf
     */
    private function validateNumber($content, ?float $minRange, ?float $maxRange, ?int $multipleOf) : void {
        if (!is_numeric($content)) return;
        if ($minRange && $content < $minRange) {
            throw new InvalidObjectValueArgumentException("min range $minRange expected, given ".$content);
        }
        if ($maxRange && $content > $maxRange) {
            throw new InvalidObjectValueArgumentException("max range $maxRange expected, given ".$content);
        }
        if ($multipleOf && $content % $multipleOf !== 0) {
            throw new InvalidObjectValueArgumentException("multipleOf $multipleOf expected, given ".$content);
        }
    }

}