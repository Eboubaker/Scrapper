<?php declare(strict_types=1);

namespace Eboubaker\Scrapper;

use Eboubaker\Scrapper\Contracts\Scrapper;
use Eboubaker\Scrapper\Exception\InvalidArgumentException;
use Eboubaker\Scrapper\Exception\UrlNotSupportedException;
use Eboubaker\Scrapper\Exception\UserException;
use ErrorException;
use Exception;
use Garden\Cli\Args;
use Garden\Cli\Cli;

final class App
{
    private static Args $arguments;

    public static function run(array $args): int
    {
        try {
            self::bootstrap($args);
            return self::run_main();
        } catch (InvalidArgumentException $e) {
            error($e->getMessage());
            if ($e->getPrevious())
                error($e->getPrevious()->getMessage());
            tell("run with --help to see usage");
            return $e->getCode();
        } catch (UserException $e) {
            error($e->getMessage());
            if (debug_enabled())
                dump_exception($e);
            return $e->getCode();
        } catch (Exception $e) {
            // display nice error message to console, or maybe bad??
            dump_exception($e);
            return $e->getCode() !== 0 ? $e->getCode() : 100;
        }
    }

    /**
     * @throws InvalidArgumentException
     * @throws ErrorException
     */
    private static function bootstrap(array $args)
    {
        // convert warnings to exceptions.
        set_error_handler(function (int    $errno,
                                    string $errstr,
                                    string $errfile,
                                    int    $errline) {
            /** @noinspection PhpUnhandledExceptionInspection */
            throw new ErrorException($errstr, $errno, 1, $errfile, $errline);
        });
        // parse arguments
        App::$arguments = self::parse_arguments($args);
        if (App::args()->getOpt('version')) {
            tell("v{}", json_decode(@file_get_contents(rootpath('composer.json')) ?? "{\"version\":\"undefined\"}", true)["version"]);
            exit(0);
        }
        // disable pcre jit because we are dealing with big chunks of text
        ini_set("pcre.jit", '0');
    }

    /**
     * @throws UrlNotSupportedException
     * @throws InvalidArgumentException
     * @throws Exception
     */
    private static function run_main(): int
    {
        // escape terminal escape char '\'
        $url = str_replace('\\', '', App::args()->getArg('url', ''));

        if (empty($url)) {
            throw new InvalidArgumentException("url was not provided");
        }

        list($html_document, $final_url) = Scrapper::load_webpage($url);

        info("attempting to determine which extractor to use");
        $scrapper = Scrapper::getRequiredScrapper($final_url);
        info("using {}", (new \ReflectionClass($scrapper))->getShortName());

        $scrapper->download_media_from_html_document($html_document);
        return 0;
    }


    public static function args(): Args
    {
        return self::$arguments;
    }

    /**
     * parseArgs Command Line Interface (CLI) utility function.
     * @throws InvalidArgumentException
     * @author              Patrick Fisher <patrick@pwfisher.com>
     * @see                 https://github.com/pwfisher/CommandLine.php
     */
    public static function parse_arguments($argv = null): Args
    {
        $cli = Cli::create()
            ->description("Download media from a post url")
            ->opt("out:o", "set output path, default is current working directory(cmd path)")
            ->opt("verbose:v", "display more useful information", false, 'bool')
            ->opt("version", "show version", false, 'bool')
            ->arg("url", "Post Url");
        try {
            if (count($argv) === 1)
                array_push($argv, "--help");
            return $cli->parse($argv, in_array("--help", $argv));
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("Invalid options", $e);
        }
    }

}