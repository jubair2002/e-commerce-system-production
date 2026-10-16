<?php
namespace PHPMailer\PHPMailer;

/**
 * PHPMailer Exception class.
 */
class Exception extends \Exception
{
    /**
     * Prettify error message output.
     *
     * @return string
     */
    public function errorMessage()
    {
        return "<strong>" . htmlspecialchars($this->getMessage(), ENT_QUOTES, "UTF-8") . "</strong><br />\n";
    }
}
