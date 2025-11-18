
<?php




class validation_utils
{
    public static function validateEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }


    public static function validateRequired($data, $requiredFields)
    {
        $errors = [];


        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[] = "$field is required";
            }
        }
        return $errors;
    }


    public static function validateLength($string, $min = 1, $max = 255)
    {
        $length = strlen($string);
        return $length >= $min && $length <= $max;
    }

    public static function validateDate($date)
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    public static function sanitizeInput($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::sanitizeInput($value);
            }
            return $data;
        }
        return htmlspecialchars(strip_tags(trim($data)));
    }

    public static function validatePassword($password)
    {
        // At least 8 characters, 1 uppercase, 1 lowercase, 1 number
        return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $password);
    }

     public static function validateNumeric($value, $min = null, $max = null) {
        if (!is_numeric($value)) return false;
        if ($min !== null && $value < $min) return false;
        if ($max !== null && $value > $max) return false;
        return true;
    }
}
