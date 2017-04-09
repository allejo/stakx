<?php

/**
 * @copyright 2017 Vladimir Jimenez
 * @license   https://github.com/allejo/stakx/blob/master/LICENSE.md MIT
 */

namespace allejo\stakx\FrontMatter;

use allejo\stakx\FrontMatter\Exception\YamlUnsupportedVariableException;
use allejo\stakx\FrontMatter\Exception\YamlVariableUndefinedException;
use allejo\stakx\Utilities\ArrayUtilities;

class Parser
{
    /**
     * The RegEx used to identify Front Matter variables.
     */
    const VARIABLE_DEF = '/(?<!\\\\)%([a-zA-Z]+)/';

    /**
     * A list of special fields in the Front Matter that will support expansion.
     *
     * @var string[]
     */
    private static $expandableFields = array('permalink');

    /**
     * Whether or not an field was expanded into several values.
     *
     * Only fields specified in $expandableFields will cause this value to be set to true
     *
     * @var bool
     */
    private $expansionUsed;

    /**
     * The current depth of the recursion for evaluating nested arrays in the Front Matter.
     *
     * @var int
     */
    private $nestingLevel;

    /**
     * The current hierarchy of the keys that are being evaluated.
     *
     * Since arrays can be nested, we'll keep track of the keys up until the current depth. This information is used for
     * error reporting
     *
     * @var array
     */
    private $yamlKeys;

    /**
     * The entire Front Matter block; evaluation will happen in place.
     *
     * @var array
     */
    private $frontMatter;

    /**
     * FrontMatterParser constructor.
     *
     * @param array $rawFrontMatter
     */
    public function __construct(&$rawFrontMatter)
    {
        $this->expansionUsed = false;
        $this->nestingLevel = 0;
        $this->yamlKeys = array();

        $this->frontMatter = &$rawFrontMatter;

        $this->handleSpecialFrontMatter();
        $this->evaluateBlock($this->frontMatter);
    }

    /**
     * True if any fields were expanded in the Front Matter block.
     *
     * @return bool
     */
    public function hasExpansion()
    {
        return $this->expansionUsed;
    }

    //
    // Special FrontMatter fields
    //

    /**
     * Special treatment for some FrontMatter variables.
     */
    private function handleSpecialFrontMatter()
    {
        $this->handleDateField();
    }

    /**
     * Special treatment for the `date` field in FrontMatter that creates three new variables: year, month, day.
     */
    private function handleDateField()
    {
        if (!isset($this->frontMatter['date']))
        {
            return;
        }

        $date = &$this->frontMatter['date'];
        $itemDate = $this->guessDateTime($date);

        if (!$itemDate === false)
        {
            $this->frontMatter['date'] = $itemDate->format('U');
            $this->frontMatter['year'] = $itemDate->format('Y');
            $this->frontMatter['month'] = $itemDate->format('m');
            $this->frontMatter['day'] = $itemDate->format('d');
        }
    }

    //
    // Evaluation
    //

    /**
     * Evaluate an array as Front Matter.
     *
     * @param array $yaml
     */
    private function evaluateBlock(&$yaml)
    {
        ++$this->nestingLevel;

        foreach ($yaml as $key => &$value)
        {
            $this->yamlKeys[$this->nestingLevel] = $key;
            $keys = implode('.', $this->yamlKeys);

            if (in_array($key, self::$expandableFields, true))
            {
                $value = $this->evaluateExpandableField($keys, $value);
            }
            elseif (is_array($value))
            {
                $this->evaluateBlock($value);
            }
            elseif (is_string($value))
            {
                $value = $this->evaluateBasicType($keys, $value);
            }
            elseif ($value instanceof \DateTime)
            {
                $value = $this->castDateTimeTimezone($value->format('U'));
            }
        }

        --$this->nestingLevel;
        $this->yamlKeys = array();
    }

    /**
     * Evaluate an expandable field.
     *
     * @param string $key
     * @param string $fmStatement
     *
     * @return array
     */
    private function evaluateExpandableField($key, $fmStatement)
    {
        if (!is_array($fmStatement))
        {
            $fmStatement = array($fmStatement);
        }

        $wip = array();

        foreach ($fmStatement as $statement)
        {
            $value = $this->evaluateBasicType($key, $statement, true);

            // Only continue expansion if there are Front Matter variables remain in the string, this means there'll be
            // Front Matter variables referencing arrays
            $expandingVars = $this->getFrontMatterVariables($value);
            if (!empty($expandingVars))
            {
                $value = $this->evaluateArrayType($key, $value, $expandingVars);
            }

            $wip[] = $value;
        }

        return $wip;
    }

    /**
     * Convert a string or an array into an array of ExpandedValue objects created through "value expansion".
     *
     * @param string $frontMatterKey     The current hierarchy of the Front Matter keys being used
     * @param string $expandableValue    The Front Matter value that will be expanded
     * @param array  $arrayVariableNames The Front Matter variable names that reference arrays
     *
     * @throws YamlUnsupportedVariableException If a multidimensional array is given for value expansion
     *
     * @return array
     */
    private function evaluateArrayType($frontMatterKey, $expandableValue, $arrayVariableNames)
    {
        if (!is_array($expandableValue))
        {
            $expandableValue = array($expandableValue);
        }

        $this->expansionUsed = true;

        foreach ($arrayVariableNames as $variable)
        {
            if (ArrayUtilities::is_multidimensional($this->frontMatter[$variable]))
            {
                throw new YamlUnsupportedVariableException("Yaml array expansion is not supported with multidimensional arrays with `$variable` for key `$frontMatterKey`");
            }

            $wip = array();

            foreach ($expandableValue as &$statement)
            {
                foreach ($this->frontMatter[$variable] as $value)
                {
                    $evaluatedValue = ($statement instanceof ExpandedValue) ? clone $statement : new ExpandedValue($statement);
                    $evaluatedValue->setEvaluated(str_replace('%' . $variable, $value, $evaluatedValue->getEvaluated()));
                    $evaluatedValue->setIterator($variable, $value);

                    $wip[] = $evaluatedValue;
                }
            }

            $expandableValue = $wip;
        }

        return $expandableValue;
    }

    /**
     * Evaluate an string for FrontMatter variables and replace them with the corresponding values.
     *
     * @param string $key          The key of the Front Matter value
     * @param string $string       The string that will be evaluated
     * @param bool   $ignoreArrays When set to true, an exception won't be thrown when an array is found with the
     *                             interpolation
     *
     * @throws YamlUnsupportedVariableException A FrontMatter variable is not an int, float, or string
     *
     * @return string The final string with variables evaluated
     */
    private function evaluateBasicType($key, $string, $ignoreArrays = false)
    {
        $variables = $this->getFrontMatterVariables($string);

        foreach ($variables as $variable)
        {
            $value = $this->getVariableValue($key, $variable);

            if (is_array($value) || is_bool($value))
            {
                if ($ignoreArrays)
                {
                    continue;
                }

                throw new YamlUnsupportedVariableException("Yaml variable `$variable` for `$key` is not a supported data type.");
            }

            $string = str_replace('%' . $variable, $value, $string);
        }

        return $string;
    }

    //
    // Variable management
    //

    /**
     * Get an array of FrontMatter variables in the specified string that need to be interpolated.
     *
     * @param string $string
     *
     * @return string[]
     */
    private function getFrontMatterVariables($string)
    {
        $variables = array();

        preg_match_all(self::VARIABLE_DEF, $string, $variables);

        // Default behavior causes $variables[0] is the entire string that was matched. $variables[1] will be each
        // matching result individually.
        return $variables[1];
    }

    /**
     * Get the value of a FM variable or throw an exception.
     *
     * @param string $key
     * @param string $varName
     *
     * @throws YamlVariableUndefinedException
     *
     * @return mixed
     */
    private function getVariableValue($key, $varName)
    {
        if (!isset($this->frontMatter[$varName]))
        {
            throw new YamlVariableUndefinedException("Yaml variable `$varName` is not defined for: $key");
        }

        return $this->frontMatter[$varName];
    }

    //
    // Utility functions
    //

    /**
     * @param string $epochTime
     *
     * @return bool|\DateTime
     */
    private function castDateTimeTimezone($epochTime)
    {
        $timezone = new \DateTimeZone(date_default_timezone_get());
        $value = \DateTime::createFromFormat('U', $epochTime);
        $value->setTimezone($timezone);

        return $value;
    }

    /**
     * @param $guess
     *
     * @return bool|\DateTime
     */
    private function guessDateTime($guess)
    {
        if ($guess instanceof \DateTime)
        {
            return $guess;
        }
        elseif (is_numeric($guess))
        {
            return $this->castDateTimeTimezone($guess);
        }

        try
        {
            return new \DateTime($guess);
        }
        catch (\Exception $e)
        {
            return false;
        }
    }
}