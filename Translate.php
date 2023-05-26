<?php

/**
 * This file is part of Blitz PHP framework.
 *
 * (c) 2022 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Translator;

use BlitzPHP\Autoloader\Autoloader;
use BlitzPHP\Autoloader\Locator;
use MessageFormatter;

/**
 * Gérer les messages système et la localisation.
 *
 * Basé sur les paramètres régionaux, construit sur l'internationalisation de PHP.
 *
 * @credit <a href="http://codeigniter.com">CodeIgniter4 - CodeIgniter\Language\Language</a>
 */
class Translate
{
    /**
     * Stocke les lignes de langue récupérées à partir des fichiers pour une récupération
	 * plus rapide lors d'une deuxième utilisation.
     */
    protected array $language = [];

    /**
     * Valeur booléenne indiquant si les bibliothèques intl existent sur le système.
     */
    protected bool $intlSupport = false;

    /**
     * Stocke les noms de fichiers qui ont été chargés afin que nous ne les chargions plus.
     */
    protected array $loadedFiles = [];

	protected ?Locator $locator = null;

	/**
	 * Constructor
	 *
	 * @param string $locale La langue/paramètres régionaux actuels avec lesquels travailler.
	 */
    public function __construct(protected string $locale)
    {
        if (class_exists(MessageFormatter::class)) {
            $this->intlSupport = true;
        }
    }

    /**
     * Définit les paramètres régionaux actuels à utiliser lors de l'exécution de recherches de chaînes.
     */
    public function setLocale(?string $locale = null): self
    {
        if ($locale !== null) {
            $this->locale = $locale;
        }

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Analyse la chaîne de langue d'un fichier, charge le fichier, si nécessaire, en obtenant la ligne.
     *
     * @return string|string[]
     */
    public function getLine(string $line, array $args = [])
    {
        // si aucun fichier n'est donné, il suffit d'analyser la ligne
        if (strpos($line, '.') === false) {
            return $this->formatMessage($line, $args);
        }

        // Analysez le nom du fichier et l'alias réel. Chargera le fichier de langue et les chaînes.
        [$file, $parsedLine] = $this->parseLine($line, $this->locale);

        $output = $this->getTranslationOutput($this->locale, $file, $parsedLine);

        if ($output === null && strpos($this->locale, '-')) {
            [$locale] = explode('-', $this->locale, 2);

            [$file, $parsedLine] = $this->parseLine($line, $locale);

            $output = $this->getTranslationOutput($locale, $file, $parsedLine);
        }

        // si toujours introuvable, essayez l'anglais
        if ($output === null) {
            [$file, $parsedLine] = $this->parseLine($line, 'en');

            $output = $this->getTranslationOutput('en', $file, $parsedLine);
        }

        $output ??= $line;

        return $this->formatMessage($output, $args);
    }

    /**
     * @return array|string|null
     */
    protected function getTranslationOutput(string $locale, string $file, string $parsedLine)
    {
        $output = $this->language[$locale][$file][$parsedLine] ?? null;
        if ($output !== null) {
            return $output;
        }

        foreach (explode('.', $parsedLine) as $row) {
            if (! isset($current)) {
                $current = $this->language[$locale][$file] ?? null;
            }

            $output = $current[$row] ?? null;
            if (is_array($output)) {
                $current = $output;
            }
        }

        if ($output !== null) {
            return $output;
        }

        $row = current(explode('.', $parsedLine));
        $key = substr($parsedLine, strlen($row) + 1);

        return $this->language[$locale][$file][$row][$key] ?? null;
    }

    /**
     * Analyse la chaîne de langue qui doit inclure le nom de fichier
	 * comme premier segment (séparé par un point).
     */
    protected function parseLine(string $line, string $locale): array
    {
        $file = substr($line, 0, strpos($line, '.'));
        $line = substr($line, strlen($file) + 1);

        if (! isset($this->language[$locale][$file]) || ! array_key_exists($line, $this->language[$locale][$file])) {
            $this->load($file, $locale);
        }

        return [$file, $line];
    }

    /**
     * Formatage avancé des messages.
     *
     * @param array|string $message
     * @param string[]     $args
     *
     * @return array|string
     */
    protected function formatMessage($message, array $args = [])
    {
        if (! $this->intlSupport || $args === []) {
            return $message;
        }

        if (is_array($message)) {
            foreach ($message as $index => $value) {
                $message[$index] = $this->formatMessage($value, $args);
            }

            return $message;
        }

        return MessageFormatter::formatMessage($this->locale, $message, $args);
    }

    /**
     * Charge un fichier de langue dans les paramètres régionaux actuels.
	 * Si $return est vrai, renverra le contenu du fichier,
	 * sinon fusionnera avec les lignes de langage existantes.
     *
     * @return array|void
     */
    protected function load(string $file, string $locale, bool $return = false)
    {
        if (! array_key_exists($locale, $this->loadedFiles)) {
            $this->loadedFiles[$locale] = [];
        }

        if (in_array($file, $this->loadedFiles[$locale], true)) {
            // Ne le chargez pas plus d'une fois.
            return [];
        }

        if (! array_key_exists($locale, $this->language)) {
            $this->language[$locale] = [];
        }

        if (! array_key_exists($file, $this->language[$locale])) {
            $this->language[$locale][$file] = [];
        }

        $path = "Translations/{$locale}/{$file}.php";

        $lang = $this->requireFile($path);

        if ($return) {
            return $lang;
        }

        $this->loadedFiles[$locale][] = $file;

        // Fusionner notre chaîne
        $this->language[$locale][$file] = $lang;
    }

    /**
     * Une méthode simple pour inclure des fichiers qui peuvent être remplacés lors des tests.
     */
    protected function requireFile(string $path): array
    {
        $files   = $this->locator()->search($path, 'php', false);
        $strings = [];

        foreach ($files as $file) {
            // Sur certains systèmes d'exploitation,
			// nous voyions des échecs sur cette commande renvoyant un booléen au lieu d'un tableau f pendant les tests,
			// nous avons donc supprimé le require_once pour l'instant.
            if (is_file($file)) {
                $strings[] = require $file;
            }
        }

        if (isset($strings[1])) {
            $string = array_shift($strings);

            $strings = array_replace_recursive($string, ...$strings);
        } elseif (isset($strings[0])) {
            $strings = $strings[0];
        }

        return $strings;
    }

	protected function locator(): Locator
	{
		if (null !== $this->locator) {
			return $this->locator;
		}

		$autoloader = new Autoloader([
            'psr4' => [__NAMESPACE__ => __DIR__]
        ]);

		return $this->locator = new Locator($autoloader->initialize());
	}
}
