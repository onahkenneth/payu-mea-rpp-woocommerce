<?php

/**
 * Class WC_PayU_XMLParser
 */
class WC_PayU_XMLParser
{
    /**
     * @var int
     */
    private $depth;

    /**
     * @var int
     */
    private $indent = 4;

    /**
     * @var string
     */
    private $padChar = ' ';

    /**
     * @var boolean
     */
    private $preserveWhitespace = false;

    /**
     * @param string $xml the IPN xm to parse
     *
     * @return array|bool
     */
    public static function xmlToArray(SimpleXMLElement $xml): bool|array
    {
        $data = [];
        $data[$xml['Stage']->getName()] = $xml['Stage']->__toString();

        foreach ($xml as $element) {
            if ($element->children()) {
                foreach ($element as $child) {
                    if ($child->attributes()) {
                        foreach ($child->attributes() as $key => $value) {
                            $data[$element->getName()][$child->getName()][$key] = $value->__toString();
                        }
                    } else {
                        $data[$element->getName()][$child->getName()] = $child->__toString();
                    }
                }
            } else {
                $data[$element->getName()] = $element->__toString();
            }
        }

        return $data;
    }

    /**
     * @param int $indent
     */
    public function setIndentSize($indent)
    {
        $this->indent = intval($indent);
    }

    /**
     * @param string $indentCharacter
     */
    public function setIndentCharacter($indentCharacter)
    {
        $this->padChar = $indentCharacter;
    }

    /**
     * @param string $xml
     * @return string
     */
    public function prettyPrint($xml)
    {
        $output = '';
        $this->depth = 0;

        $parts = $this->getXmlParts($xml);

        if (strpos($parts[0], '<?xml') === 0) {
            $output = array_shift($parts) . PHP_EOL;
        }

        foreach ($parts as $part) {
            $output .= $this->getOutputForPart($part);
        }

        return trim($output);
    }

    /**
     * @param string $xml
     * @return array
     */
    private function getXmlParts($xml)
    {
        $withNewLines = preg_replace('/(>)(<)(\/*)/', "$1\n$2$3", trim($xml));
        return explode("\n", $withNewLines);
    }

    /**
     * @param string $part
     * @return string
     */
    private function getOutputForPart($part)
    {
        $output = '';
        $this->runPre($part);

        if ($this->preserveWhitespace) {
            $output .= $part . PHP_EOL;
        } else {
            $part = trim($part);
            $output .= $this->getPaddedString($part) . PHP_EOL;
        }

        $this->runPost($part);

        return $output;
    }

    /**
     * @param string $part
     */
    private function runPre($part)
    {
        if ($this->isClosingTag($part)) {
            $this->depth--;
        }
    }

    /**
     * @param string $part
     */
    private function runPost($part)
    {
        if ($this->isOpeningTag($part)) {
            $this->depth++;
        }
        if ($this->isClosingCdataTag($part)) {
            $this->preserveWhitespace = false;
        }
        if ($this->isOpeningCdataTag($part)) {
            $this->preserveWhitespace = true;
        }
    }

    /**
     * @param string $part
     * @return string
     */
    private function getPaddedString($part)
    {
        return str_pad($part, strlen($part) + ($this->depth * $this->indent), $this->padChar, STR_PAD_LEFT);
    }

    /**
     * @param string $part
     * @return boolean
     */
    private function isOpeningTag($part)
    {
        return (bool) preg_match('/^<[^\/]*>$/', $part);
    }

    /**
     * @param string $part
     * @return boolean
     */
    private function isClosingTag($part)
    {
        return (bool) preg_match('/^\s*<\//', $part);
    }

    /**
     * @param string $part
     * @return boolean
     */
    private function isOpeningCdataTag($part)
    {
        return strpos($part, '<![CDATA[') !== false;
    }

    /**
     * @param string $part
     * @return boolean
     */
    private function isClosingCdataTag($part)
    {
        return strpos($part, ']]>') !== false;
    }
}
