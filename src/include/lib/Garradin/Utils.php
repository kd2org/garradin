<?php

namespace Garradin;

use KD2\Security;
use KD2\Form;
use KD2\HTTP;
use KD2\Translate;
use KD2\SMTP;

class Utils
{
    const EMAIL_CONTEXT_BULK = 'bulk';
    const EMAIL_CONTEXT_PRIVATE = 'private';
    const EMAIL_CONTEXT_SYSTEM = 'system';

    static protected $collator;
    static protected $transliterator;

    const FRENCH_DATE_NAMES = [
        'January'=>'Janvier', 'February'=>'Février', 'March'=>'Mars', 'April'=>'Avril', 'May'=>'Mai',
        'June'=>'Juin', 'July'=>'Juillet', 'August'=>'Août', 'September'=>'Septembre', 'October'=>'Octobre',
        'November'=>'Novembre', 'December'=>'Décembre', 'Monday'=>'Lundi', 'Tuesday'=>'Mardi', 'Wednesday'=>'Mercredi',
        'Thursday'=>'Jeudi','Friday'=>'Vendredi','Saturday'=>'Samedi','Sunday'=>'Dimanche',
        'Feb'=>'Fév','Apr'=>'Avr','Jun'=>'Juin', 'Jul'=>'Juil','Aug'=>'Aout','Dec'=>'Déc',
        'Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mer','Thu'=>'Jeu','Fri'=>'Ven','Sat'=>'Sam','Sun'=>'Dim'];

    static public function get_datetime($ts)
    {
        if (is_object($ts) && $ts instanceof \DateTimeInterface) {
            return $ts;
        }
        elseif (is_numeric($ts)) {
            $ts = new \DateTime('@' . $ts);
            $ts->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            return $ts;
        }
        elseif (strlen($ts) == 10) {
            return \DateTime::createFromFormat('!Y-m-d', $ts);
        }
        elseif (strlen($ts) == 19) {
            return \DateTime::createFromFormat('Y-m-d H:i:s', $ts);
        }
        else {
            return null;
        }
    }

    static public function strftime_fr($ts, $format)
    {
        $ts = self::get_datetime($ts);

        if (null === $ts) {
            return $ts;
        }

        $date = strftime($format, $ts->getTimestamp());

        $date = strtr($date, self::FRENCH_DATE_NAMES);
        $date = strtolower($date);
        return $date;
    }

    static public function date_fr($ts, $format = null)
    {
        $ts = self::get_datetime($ts);

        if (null === $ts) {
            return $ts;
        }

        if (is_null($format))
        {
            $format = 'd/m/Y à H:i';
        }

        $date = $ts->format($format);

        $date = strtr($date, self::FRENCH_DATE_NAMES);
        $date = strtolower($date);
        return $date;
    }

    /**
     * @deprecated
     */
    static public function checkDate($str)
    {
        if (!preg_match('!^(\d{4})-(\d{2})-(\d{2})$!', $str, $match))
            return false;

        if (!checkdate($match[2], $match[3], $match[1]))
            return false;

        return true;
    }

    /**
     * @deprecated
     */
    static public function checkDateTime($str)
    {
        if (!preg_match('!^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})!', $str, $match))
            return false;

        if (!self::checkDate($match[1]))
            return false;

        if ((int) $match[2] < 0 || (int) $match[2] > 23)
            return false;

        if ((int) $match[3] < 0 || (int) $match[3] > 59)
            return false;

        if (isset($match[4]) && ((int) $match[4] < 0 || (int) $match[4] > 59))
            return false;

        return true;
    }

    static public function moneyToInteger($value)
    {
        if (trim($value) === '') {
            return 0;
        }

        if (!preg_match('/^-?(\d+)(?:[,.](\d{1,2}))?$/', $value, $match)) {
            throw new UserException(sprintf('Le montant est invalide : %s. Exemple de format accepté : 142,02', $value));
        }

        $value = $match[1] . str_pad(@$match[2], 2, '0', STR_PAD_RIGHT);
        $value = (int) $value;
        return $value;
    }

    static public function money_format($number, string $dec_point = ',', string $thousands_sep = ' ', $zero_if_empty = true): string {
        if ($number == 0) {
            return $zero_if_empty ? '0' : '0,00';
        }

        $sign = $number < 0 ? '-' : '';
        $number = abs((int) $number);

        $decimals = substr('0' . $number, -2);
        $number = (int) substr($number, 0, -2);

        return sprintf('%s%s%s%s', $sign, number_format($number, 0, $dec_point, $thousands_sep), $dec_point, $decimals);
    }

    static public function getLocalURL(string $url = '', ?string $default_prefix = null): string
    {
        if ($url[0] == '!') {
            return ADMIN_URL . substr($url, 1);
        }
        elseif ($url[0] == '/' && ($pos = strpos($url, WWW_URI)) === 0) {
            return WWW_URL . substr($url, strlen(WWW_URI));
        }
        elseif (substr($url, 0, 5) == 'http:' || substr($url, 0, 6) == 'https:') {
            return $url;
        }
        elseif ($url == '') {
            return ADMIN_URL;
        }
        else {
            if (null !== $default_prefix) {
                $default_prefix = self::getLocalURL($default_prefix);
            }

            return $default_prefix . $url;
        }
    }

    static public function getRequestURI()
    {
        if (!empty($_SERVER['REQUEST_URI']))
            return $_SERVER['REQUEST_URI'];
        else
            return false;
    }

    static public function getSelfURL($qs = true)
    {
        $uri = self::getSelfURI($qs);

        // Make absolute URI relative to parent URI
        if (strpos($uri, WWW_URI . 'admin/') === 0)
        {
            $uri = substr($uri, strlen(WWW_URI . 'admin/'));
        }

        return ADMIN_URL . $uri;
    }

    static public function getSelfURI($qs = true)
    {
        $uri = self::getRequestURI();

        if ($qs !== true && (strpos($uri, '?') !== false))
        {
            $uri = substr($uri, 0, strpos($uri, '?'));
        }

        if (is_array($qs))
        {
            $uri .= '?' . http_build_query($qs);
        }

        return $uri;
    }

    static public function getModifiedURL(string $new)
    {
        return HTTP::mergeURLs(self::getSelfURL(), $new);
    }

    static public function reloadParentFrame(?string $destination = null): void
    {
        $url = self::getLocalURL($destination ?? '!');

        echo '
            <!DOCTYPE html>
            <html>
            <head>
                <script type="text/javascript">';

        if (null === $destination) {
            echo 'window.parent.location.reload();';
        }
        else {
            printf('window.parent.location.href = %s;', json_encode($url));
        }

        echo '
                </script>
            </head>

            <body>
            <p style="visibility: hidden;"><a href="' . htmlspecialchars($url) . '">Cliquer ici pour continuer</a>
            </body>
            </html>';

        exit;
    }

    public static function redirect($destination = '', $exit=true)
    {
        $destination = self::getLocalURL($destination);

        if (PHP_SAPI == 'cli') {
            echo 'Please visit ' . $destination . PHP_EOL;
            exit;
        }

        if (headers_sent())
        {
            echo
              '<html>'.
              ' <head>' .
              '  <script type="text/javascript">' .
              '    document.location = "' . htmlspecialchars($destination, ENT_QUOTES, 'UTF-8', false) . '";' .
              '  </script>' .
              ' </head>'.
              ' <body>'.
              '   <div>'.
              '     <a href="' . htmlspecialchars($destination, ENT_QUOTES, 'UTF-8', false) . '">Cliquez ici pour continuer...</a>'.
              '   </div>'.
              ' </body>'.
              '</html>';

            if ($exit)
              exit();

            return true;
        }

        header("Location: " . $destination);

        if ($exit)
          exit();
    }

    static public function getIP()
    {
        if (!empty($_SERVER['REMOTE_ADDR']))
            return $_SERVER['REMOTE_ADDR'];
        return '';
    }

    static public function getCountryList()
    {
        return Translate::getCountriesList('fr');
    }

    static public function getCountryName($code)
    {
        $list = self::getCountryList();

        if (!isset($list[$code]))
            return false;

        return $list[$code];
    }

    static public function transliterateToAscii($str, $charset='UTF-8')
    {
        // Don't process empty strings
        if (!trim($str))
            return $str;

        // We only process non-ascii strings
        if (preg_match('!^[[:ascii:]]+$!', $str))
            return $str;

        $str = htmlentities($str, ENT_NOQUOTES, $charset);

        $str = preg_replace('#&([A-za-z])(?:acute|cedil|circ|grave|orn|ring|slash|th|tilde|uml);#', '\1', $str);
        $str = preg_replace('#&([A-za-z]{2})(?:lig);#', '\1', $str); // pour les ligatures e.g. '&oelig;'

        $str = preg_replace('#&[^;]+;#', '', $str); // supprime les autres caractères
        $str = preg_replace('![^[:ascii:]]+!', '', $str);

        return $str;
    }

    /**
     * Transforme les tags HTML basiques en tags SkrivML
     * @param  string $str Texte d'entrée
     * @return string      Texte transformé
     */
    static public function HTMLToSkriv($str)
    {
        $str = preg_replace('/<h3>(\V*?)<\/h3>/', '=== $1 ===', $str);
        $str = preg_replace('/<b>(\V*)<\/b>/', '**$1**', $str);
        $str = preg_replace('/<strong>(\V*?)<\/strong>/', '**$1**', $str);
        $str = preg_replace('/<i>(\V*?)<\/i>/', '\'\'$1\'\'', $str);
        $str = preg_replace('/<em>(\V*?)<\/em>/', '\'\'$1\'\'', $str);
        $str = preg_replace('/<li>(\V*?)<\/li>/', '* $1', $str);
        $str = preg_replace('/<ul>|<\/ul>/', '', $str);
        $str = preg_replace('/<a href="([^"]*?)">(\V*?)<\/a>/', '[[$2 | $1]]', $str);
        return $str;
    }

    static public function safe_unlink($path)
    {
        if (!@unlink($path))
        {
            return true;
        }

        if (!file_exists($path))
        {
            return true;
        }

        throw new \RuntimeException(sprintf('Impossible de supprimer le fichier %s: %s', $path, error_get_last()));

        return true;
    }

    static public function safe_mkdir($path, $mode = 0777, $recursive = false)
    {
        return @mkdir($path, $mode, $recursive) || is_dir($path);
    }

    static public function suggestPassword()
    {
        return Security::getRandomPassphrase(ROOT . '/include/data/dictionary.fr');
    }

    static public function normalizePhoneNumber($n)
    {
        return preg_replace('![^\d\+\(\)p#,;-]!', '', trim($n));
    }

    static public function write_ini_string($in)
    {
        $out = '';
        $get_ini_line = function ($key, $value) use (&$get_ini_line)
        {
            if (is_bool($value))
            {
                return $key . ' = ' . ($value ? 'true' : 'false');
            }
            elseif (is_numeric($value))
            {
                return $key . ' = ' . $value;
            }
            elseif (is_array($value) || is_object($value))
            {
                $out = '';
                $value = (array) $value;
                foreach ($value as $row)
                {
                    $out .= $get_ini_line($key . '[]', $row) . "\n";
                }

                return substr($out, 0, -1);
            }
            else
            {
                return $key . ' = "' . str_replace('"', '\\"', $value) . '"';
            }
        };

        foreach ($in as $key=>$value)
        {
            if ((is_array($value) || is_object($value)) && is_string($key))
            {
                $out .= '[' . $key . "]\n";

                foreach ($value as $row_key=>$row_value)
                {
                    $out .= $get_ini_line($row_key, $row_value) . "\n";
                }

                $out .= "\n";
            }
            else
            {
                $out .= $get_ini_line($key, $value) . "\n";
            }
        }

        return $out;
    }

    static public function getMaxUploadSize()
    {
        $limits = [
            self::return_bytes(ini_get('upload_max_filesize')),
            self::return_bytes(ini_get('post_max_size')),
            self::return_bytes(ini_get('memory_limit'))
        ];

        return min(array_filter($limits));
    }


    static public function return_bytes($size_str)
    {
        if ($size_str == '-1')
        {
            return false;
        }

        switch (substr($size_str, -1))
        {
            case 'G': case 'g': return (int)$size_str * pow(1024, 3);
            case 'M': case 'm': return (int)$size_str * pow(1024, 2);
            case 'K': case 'k': return (int)$size_str * 1024;
            default: return $size_str;
        }
    }

    static public function format_bytes($size)
    {
        if ($size > (1024 * 1024 * 1024)) {
            $size = round($size / 1024 / 1024 / 1024, 2);
            $decimals = $size == (int) $size ? 0 : 2;
            return number_format($size, $decimals, ',', '') . ' Go';
        }
        elseif ($size > (1024 * 1024)) {
            $size = round($size / 1024 / 1024, 2);
            $decimals = $size == (int) $size ? 0 : 2;
            return number_format($size, $decimals, ',', '') . ' Mo';
        }
        elseif ($size > 1024) {
            return round($size / 1024) . ' Ko';
        }
        else
            return $size . ' o';
    }

    static public function createEmptyDirectory(string $path)
    {
        Utils::safe_mkdir($path, 0777, true);

        if (!is_dir($path))
        {
            throw new UserException('Le répertoire '.$path.' n\'existe pas ou n\'est pas un répertoire.');
        }

        // On en profite pour vérifier qu'on peut y lire et écrire
        if (!is_writable($path) || !is_readable($path))
        {
            throw new UserException('Le répertoire '.$path.' n\'est pas accessible en lecture/écriture.');
        }

        // Some basic safety against misconfigured hosts
        file_put_contents($path . '/index.html', '<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN"><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL was not found on this server.</p></body></html>');
    }

    static public function resetCache(string $path): void
    {
        if (!file_exists($path)) {
            self::createEmptyDirectory($path);
            return;
        }

        $dir = dir($path);

        while ($file = $dir->read()) {
            if (substr($file, 0, 1) == '.' || is_dir($path . DIRECTORY_SEPARATOR . $file)) {
                continue;
            }

            self::safe_unlink($path . DIRECTORY_SEPARATOR . $file);
        }

        $dir->close();
    }

    static public function deleteRecursive(string $path, bool $delete_self = false): bool
    {
        if (!file_exists($path))
            return false;

        $dir = dir($path);
        if (!$dir) return false;

        while ($file = $dir->read())
        {
            if ($file == '.' || $file == '..')
                continue;

            if (is_dir($path . DIRECTORY_SEPARATOR . $file))
            {
                if (!self::deleteRecursive($path . DIRECTORY_SEPARATOR . $file))
                    return false;
            }
            else
            {
                self::safe_unlink($path . DIRECTORY_SEPARATOR . $file);
            }
        }

        $dir->close();
        rmdir($path);

        return true;
    }

    static public function plugin_url($params = [])
    {
        if (isset($params['id']))
        {
            $url = ADMIN_URL . 'plugin/' . $params['id'] . '/';
        }
        elseif (defined('Garradin\PLUGIN_URL'))
        {
            $url = PLUGIN_URL;
        }
        else {
            throw new \RuntimeException('Missing plugin URL');
        }

        if (!empty($params['file']))
            $url .= $params['file'];

        if (!empty($params['query']))
        {
            $url .= '?';
            
            if (!(is_numeric($params['query']) && (int)$params['query'] === 1) && $params['query'] !== true)
                $url .= $params['query'];
        }

        return $url;
    }

    static public function sendEmail($context, $recipient, $subject, $content, $id_membre = null, $pgp_key = null)
    {
        // Ne pas envoyer de mail à des adresses invalides
        if (!SMTP::checkEmailIsValid($recipient, false))
        {
            throw new UserException('Adresse email invalide: ' . $recipient);
        }

        $config = Config::getInstance();
        $subject = sprintf('[%s] %s', $config->get('nom_asso'), $subject);

        // Tentative d'envoi du message en utilisant un plugin
        $email_sent_via_plugin = Plugin::fireSignal('email.envoi', compact('context', 'recipient', 'subject', 'content', 'id_membre', 'pgp_key'));

        if (!$email_sent_via_plugin)
        {
            // L'envoi d'email n'a pas été effectué par un plugin, utilisons l'envoi interne
            // via mail() ou SMTP donc
            return self::mail($context, $recipient, $subject, $content, $id_membre, $pgp_key);
        }

        return true;
    }

    static public function mail($context, $to, $subject, $content, $id_membre, $pgp_key)
    {
        $headers = [];
        $config = Config::getInstance();

        $content = wordwrap($content);
        $content = trim($content);

        $content .= sprintf("\n\n-- \n%s\n%s\n\n", $config->get('nom_asso'), $config->get('site_asso'));
        $content .= "Vous recevez ce message car vous êtes inscrit comme membre de\nl'association.\n";
        $content .= "Pour ne plus recevoir de message de notre part merci de nous contacter :\n" . $config->get('email_asso');

        $content = preg_replace("#(?<!\r)\n#si", "\r\n", $content);

        if ($pgp_key)
        {
            $content = Security::encryptWithPublicKey($pgp_key, $content);
        }

        $headers['From'] = sprintf('"%s" <%s>', sprintf('=?UTF-8?B?%s?=', base64_encode($config->get('nom_asso'))), $config->get('email_asso'));
        $headers['Return-Path'] = $config->get('email_asso');

        $headers['MIME-Version'] = '1.0';
        $headers['Content-type'] = 'text/plain; charset=UTF-8';

        if ($context == self::EMAIL_CONTEXT_BULK)
        {
            $headers['Precedence'] = 'bulk';
        }

        $hash = sha1(uniqid() . var_export([$headers, $to, $subject, $content], true));
        $headers['Message-ID'] = sprintf('%s@%s', $hash, isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : gethostname());

        if (SMTP_HOST)
        {
            $const = '\KD2\SMTP::' . strtoupper(SMTP_SECURITY);

            if (!defined($const))
            {
                throw new \LogicException('Configuration: SMTP_SECURITY n\'a pas une valeur reconnue. Valeurs acceptées: STARTTLS, TLS, SSL, NONE.');
            }

            $secure = constant($const);

            $smtp = new SMTP(SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASSWORD, $secure);
            return $smtp->send($to, $subject, $content, $headers);
        }
        else
        {
            // Encodage du sujet
            $subject = sprintf('=?UTF-8?B?%s?=', base64_encode($subject));
            $raw_headers = '';

            // Sérialisation des entêtes
            foreach ($headers as $name=>$value)
            {
                $raw_headers .= sprintf("%s: %s\r\n", $name, $value);
            }

            return \mail($to, $subject, $content, $raw_headers);
        }
    }

    static public function iconUnicode(string $shape): string
    {
        switch ($shape) {
            case 'up': return '↑';
            case 'down': return '↓';
            case 'export': return '↷';
            case 'reset': return '↺';
            case 'upload': return '⇑';
            case 'download': return '⇓';
            case 'home': return '⌂';
            case 'print': return '⎙';
            case 'star': return '★';
            case 'check': return '☑';
            case 'settings': return '☸';
            case 'alert': return '⚠';
            case 'mail': return '✉';
            case 'edit': return '✎';
            case 'delete': return '✘';
            case 'help': return '❓';
            case 'plus': return '➕';
            case 'minus': return '➖';
            case 'logout': return '⤝';
            case 'eye-off': return '⤫';
            case 'menu': return '𝍢';
            case 'eye': return '👁';
            case 'user': return '👤';
            case 'users': return '👪';
            case 'calendar': return '📅';
            case 'attach': return '📎';
            case 'search': return '🔍';
            case 'lock': return '🔒';
            case 'unlock': return '🔓';
            case 'folder': return '🗀';
            case 'document': return '🗅';
            case 'bold': return 'B';
            case 'italic': return 'I';
            case 'header': return 'H';
            case 'paragraph': return '§';
            case 'list-ol': return 'ģ';
            case 'list-ul': return '•';
            case 'table': return '◫';
            case 'radio-unchecked': return '◯';
            case 'uncheck': return '☐';
            case 'radio-checked': return '⬤';
            case 'image': return '🖻';
            case 'left': return '←';
            case 'right': return '→';
            default:
                throw new \InvalidArgumentException('Unknown icon shape: ' . $shape);
        }
    }

    static public function array_transpose(array $array): array
    {
        $out = [];
        $max = 0;

        foreach ($array as $rows) {
            $max = max($max, count($rows));
        }

        foreach ($array as $column => $rows) {
            // Match number of rows of largest sub-array, in case there is a missing row in a column
            if ($max != count($rows)) {
                $rows = array_merge($rows, array_fill(0, $max - count($rows), null));
            }

            foreach ($rows as $k => $v) {
                if (!isset($out[$k])) {
                    $out[$k] = [];
                }

                $out[$k][$column] = $v;
            }
        }

        return $out;
    }

    static public function rgbHexToDec(string $hex)
    {
        return sscanf($hex, '#%02x%02x%02x');
    }

    /**
     * Converts an RGB color value to HSV. Conversion formula
     * adapted from http://en.wikipedia.org/wiki/HSV_color_space.
     * Assumes r, g, and b are contained in the set [0, 255] and
     * returns h, s, and v in the set [0, 1].
     *
     * @param   Number  r       The red color value
     * @param   Number  g       The green color value
     * @param   Number  b       The blue color value
     * @return  Array           The HSV representation
     */
    static public function rgbToHsv($r, $g = null, $b = null)
    {
        if (is_string($r) && is_null($g) && is_null($b))
        {
            list($r, $g, $b) = self::rgbHexToDec($r);
        }

        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $h = $s = $v = $max;

        $d = $max - $min;
        $s = ($max == 0) ? 0 : $d / $max;

        if($max == $min)
        {
            $h = 0; // achromatic
        }
        else
        {
            switch($max)
            {
                case $r: $h = ($g - $b) / $d + ($g < $b ? 6 : 0); break;
                case $g: $h = ($b - $r) / $d + 2; break;
                case $b: $h = ($r - $g) / $d + 4; break;
            }
            $h /= 6;
        }

        return array($h * 360, $s, $v);
    }

    static public function HTTPCache(?string $hash, int $last_change): bool
    {
        $etag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : null;
        $last_modified = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : null;

        if ($etag === $hash && $last_modified >= $last_change) {
            header('HTTP/1.1 304 Not Modified', true, 304);
            exit;
        }

        header(sprintf('Last-Modified: %s GMT', gmdate('D, d M Y H:i:s', $last_change)));

        if ($etag) {
            header(sprintf('Etag: %s', $hash));
        }

        header('Cache-Control: private');

        return false;
    }

    static public function getLatestVersion(): ?string
    {
        $config = Config::getInstance();
        $last = $config->get('last_version_check');

        if ($last) {
            $last = json_decode($last);
        }

        // Only check once every two weeks
        if ($last && $last->time > (time() - 3600 * 24 * 15)) {
            return $last->version;
        }

        $current_version = garradin_version();
        $last = (object) ['time' => time(), 'version' => null];
        $config->set('last_version_check', json_encode($last));
        $config->save();

        $list = (new HTTP)->GET(WEBSITE . 'juvlist');

        if (!$list) {
            return null;
        }

        $list = json_decode($list);

        if (!$list) {
            return null;
        }

        $last->version = $current_version;

        foreach ($list as $item) {
            if (preg_match('/^garradin-(.*)\.tar\.bz2$/', $item->name, $match) && !preg_match('/alpha|dev|rc|beta/', $match[1])
                && version_compare($last->version, $match[1], '<')) {
                $last->version = $match[1];
            }
        }

        if (version_compare($last->version, $current_version, '==')) {
            $last->version = null;
        }

        $config->set('last_version_check', json_encode($last));
        $config->save();

        return $last->version;
    }

    static public function transformTitleToURI($str)
    {
        $str = Utils::transliterateToAscii($str);

        $str = preg_replace('![^\w\d_-]!i', '-', $str);
        $str = preg_replace('!-{2,}!', '-', $str);
        $str = trim($str, '-');

        return $str;
    }

    /**
     * dirname may have undefined behaviour depending on the locale!
     */
    static public function dirname(string $str): string
    {
        $str = str_replace(DIRECTORY_SEPARATOR, '/', $str);
        return substr($str, 0, strrpos($str, '/'));
    }

    /**
     * basename may have undefined behaviour depending on the locale!
     */
    static public function basename(string $str): string
    {
        $str = str_replace(DIRECTORY_SEPARATOR, '/', $str);
        $str = trim($str, '/');
        $str = substr($str, strrpos($str, '/'));
        $str = trim($str, '/');
        return $str;
    }

    static public function unicodeCaseComparison($a, $b): int
    {
        if (!isset(self::$collator) && function_exists('collator_create')) {
            self::$collator = \Collator::create('fr_FR');

            // This is what makes the comparison case insensitive
            // https://www.php.net/manual/en/collator.setstrength.php
            self::$collator->setAttribute(\Collator::STRENGTH, \Collator::SECONDARY);

            // Don't use \Collator::NUMERIC_COLLATION here as it goes against what would feel logic
            // with NUMERIC_COLLATION: 1, 2, 10, 11, 101
            // without: 1, 10, 101, 11, 2
        }

        if (isset(self::$collator)) {
            return (int) self::$collator->compare($a, $b);
        }

        $a = strtoupper(self::transliterateToAscii($a));
        $b = strtoupper(self::transliterateToAscii($b));

        return strcmp($a, $b);
    }

    /**
     * Transforms a unicode string to lowercase AND removes all diacritics
     *
     * @see https://www.matthecat.com/supprimer-les-accents-d-une-chaine-avec-php.html
     */
    static public function unicodeCaseFold(?string $str): string
    {
        if (null === $str || trim($str) === '') {
            return '';
        }

        if (!isset(self::$transliterator) && function_exists('transliterator_create')) {
            self::$transliterator = \Transliterator::create('Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; [:Punctuation:] Remove; Lower();');
        }

        if (isset(self::$transliterator)) {
            return self::$transliterator->transliterate($str);
        }

        return strtoupper(self::transliterateToAscii($str));
    }

    static public function knatcasesort(array $array)
    {
        uksort($array, [self::class, 'unicodeCaseComparison']);
        return $array;
    }
}
