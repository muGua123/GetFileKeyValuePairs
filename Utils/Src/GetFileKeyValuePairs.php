<?php


namespace Utils\Src;

use Utils\InvalidFileException;
use Utils\InvalidPathException;

class GetFileKeyValuePairs
{
    const INITIAL_STATE = 0;
    const QUOTED_STATE = 1;
    const ESCAPE_STATE = 2;
    const WHITESPACE_STATE = 3;
    const COMMENT_STATE = 4;

    /**
     * The file path.
     *
     * @var string
     */
    protected $filePath;

    /**
     * File content array
     *
     * @var array
     */
    protected $array = [];

    /**
     * Set filePath value.
     *
     * @param filePath
     * @return $this
     */
    public function setFilePath($filePath = '')
    {
        $this->filePath = $filePath;

        return $this;
    }

    /**
     * Get array.
     *
     * @return $array
     */
    public function getArray()
    {
        return $this->array;
    }

    //
    public function deal()
    {
        $this->ensureFileIsReadable($this->filePath);

        $lines = $this->readLinesFromFile($this->filePath);

        foreach ($lines as $line) {
            if (!$this->isComment($line) && $this->looksLikeSetter($line)) {
                $this->normaliseEnvironmentVariable($line);
            }
        }
    }

    /**
     * Ensures the given filePath is readable.
     *
     * @return void
     * @throws \Dotenv\Exception\InvalidPathException
     *
     */
    public function ensureFileIsReadable()
    {
        if (!is_readable($this->filePath) || !is_file($this->filePath)) {
            throw new InvalidPathException(sprintf('Unable to read the environment file at %s.', $this->filePath));
        }
    }

    /**
     * Read lines from the file, auto detecting line endings.
     *
     * @param string $filePath
     *
     * @return array
     */
    public function readLinesFromFile()
    {
        $autodetect = ini_get('auto_detect_line_endings');
        ini_set('auto_detect_line_endings', '1');
        $lines = file($this->filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        ini_set('auto_detect_line_endings', $autodetect);

        return $lines;
    }

    /**
     * Determine if the line in the file is a comment, e.g. begins with a #.
     *
     * @param string $line
     *
     * @return bool
     */
    public function isComment($line)
    {
        $line = ltrim($line);

        return isset($line[0]) && $line[0] === '#';
    }

    /**
     * Determine if the given line looks like it's setting a variable.
     *
     * @param string $line
     *
     * @return bool
     */
    public function looksLikeSetter($line)
    {
        return strpos($line, '=') !== false;
    }

    /**
     * Normalise the given environment variable.
     *
     * Takes value as passed in by developer and:
     * - ensures we're dealing with a separate name and value, breaking apart the name string if needed,
     * - cleaning the value of quotes,
     * - cleaning the name of quotes,
     *
     * @param string $name
     * @param string $value
     *
     * @return array
     * @throws \Dotenv\Exception\InvalidFileException
     *
     */
    public function normaliseEnvironmentVariable($name, $value = null)
    {
        list($name, $value) = $this->processFilters($name, $value);
        $this->array["$name"] = $value;
    }

    /**
     * Process the runtime filters.
     *
     * @param string $name
     * @param string $value
     *
     * @return array
     * @throws \Dotenv\Exception\InvalidFileException
     *
     */
    public function processFilters($name, $value)
    {
        list($name, $value) = $this->splitCompoundStringIntoParts($name, $value);
        list($name, $value) = $this->sanitiseVariableValue($name, $value);

        return array($name, $value);
    }

    /**
     * Split the compound string into parts.
     *
     * If the `$name` contains an `=` sign, then we split it into 2 parts, a `name` & `value`
     * disregarding the `$value` passed in.
     *
     * @param string $name
     * @param string $value
     *
     * @return array
     */
    public function splitCompoundStringIntoParts($name, $value)
    {
        if (strpos($name, '=') !== false) {
            list($name, $value) = array_map('trim', explode('=', $name, 2));
        }

        return array($name, $value);
    }

    /**
     * Strips quotes from the environment variable value.
     *
     * @param string $name
     * @param string $value
     *
     * @return array
     * @throws \Dotenv\Exception\InvalidFileException
     *
     */
    public function sanitiseVariableValue($name, $value)
    {
        $value = trim($value);
        if (!$value) {
            return array($name, $value);
        }

        return array($name, $this->parseValue($value));
    }

    /**
     * Parse the given variable value.
     *
     * @param string $value
     *
     * @return string
     * @throws \Dotenv\Exception\InvalidFileException
     *
     */
    public function parseValue($value)
    {
        if ($value === '') {
            return '';
        } elseif ($value[0] === '"' || $value[0] === '\'') {
            return $this->parseQuotedValue($value);
        } else {
            return $this->parseUnquotedValue($value);
        }
    }

    /**
     * Parse the given quoted value.
     *
     * @param string $value
     *
     * @return string
     * @throws \Dotenv\Exception\InvalidFileException
     *
     */
    public function parseQuotedValue($value)
    {
        $data = array_reduce(str_split($value), function ($data, $char) use ($value) {
            switch ($data[1]) {
                case self::INITIAL_STATE:
                    if ($char === '"' || $char === '\'') {
                        return array($data[0], self::QUOTED_STATE);
                    } else {
                        throw new InvalidFileException(
                            'Expected the value to start with a quote.'
                        );
                    }
                case self::QUOTED_STATE:
                    if ($char === $value[0]) {
                        return array($data[0], self::WHITESPACE_STATE);
                    } elseif ($char === '\\') {
                        return array($data[0], self::ESCAPE_STATE);
                    } else {
                        return array($data[0] . $char, self::QUOTED_STATE);
                    }
                case self::ESCAPE_STATE:
                    if ($char === $value[0] || $char === '\\') {
                        return array($data[0] . $char, self::QUOTED_STATE);
                    } else {
                        return array($data[0] . '\\' . $char, self::QUOTED_STATE);
                    }
                case self::WHITESPACE_STATE:
                    if ($char === '#') {
                        return array($data[0], self::COMMENT_STATE);
                    } elseif (!ctype_space($char)) {
                        throw new InvalidFileException(
                            'Dotenv values containing spaces must be surrounded by quotes.'
                        );
                    } else {
                        return array($data[0], self::WHITESPACE_STATE);
                    }
                case self::COMMENT_STATE:
                    return array($data[0], self::COMMENT_STATE);
            }
        }, array('', self::INITIAL_STATE));

        return trim($data[0]);
    }

    /**
     * Parse the given unquoted value.
     *
     * @param string $value
     *
     * @return string
     * @throws \Dotenv\Exception\InvalidFileException
     *
     */
    public function parseUnquotedValue($value)
    {
        $parts = explode(' #', $value, 2);
        $value = trim($parts[0]);

        // Unquoted values cannot contain whitespace
        if (preg_match('/\s+/', $value) > 0) {
            // Check if value is a comment (usually triggered when empty value with comment)
            if (preg_match('/^#/', $value) > 0) {
                $value = '';
            } else {
                throw new InvalidFileException('Dotenv values containing spaces must be surrounded by quotes.');
            }
        }

        return trim($value);
    }
}
