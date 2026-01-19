<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Stichoza\GoogleTranslate\GoogleTranslate;

class FindAndTranslatePhrases extends Command
{
    protected $signature = 'translations:scan';
    protected $description = 'Scan all files under app folder and extract all unique phrases used in trans() function';


    public function handle()
    {
        $uaJsonFile = base_path('resources/lang/uk.json');
        $uaTranslations = json_decode(File::get($uaJsonFile), true);

        $appFolder = app_path();
        $phpFiles = File::allFiles($appFolder, ['php']);

        $translatablePhrases = [];

        $excludedFiles = ['CreateModelAndController.php'];

        foreach ($phpFiles as $file) {
            if (in_array($file->getFilename(), $excludedFiles)) {
                continue;
            }

            $content = File::get($file->getPathname());
            preg_match_all('/trans\(\'(.+?)\'\)/', $content, $singleQuoteMatches);
            $translatablePhrases = array_merge($translatablePhrases, $singleQuoteMatches[1]);

            preg_match_all('/trans\(\"(.+?)\"\)/', $content, $doubleQuoteMatches);
            $translatablePhrases = array_merge($translatablePhrases, $doubleQuoteMatches[1]);

            preg_match_all('/trans\(\'(.+?)\'\,\s*\[([^\]]+)\]\)/', $content, $arraySingleQuoteMatches);
            $translatablePhrases = array_merge($translatablePhrases, $arraySingleQuoteMatches[1]);

            preg_match_all('/trans\(\"(.+?)\"\s*,\s*\[([^\]]+)\]\)/', $content, $arrayDoubleQuoteMatches);
            $translatablePhrases = array_merge($translatablePhrases, $arrayDoubleQuoteMatches[1]);
        }

        // get list of all models, get all columns from each model, and add them to translatablePhrases
        $modelsFolder = app_path('Models');
        $modelFiles = File::allFiles($modelsFolder, ['php']);

        foreach ($modelFiles as $file) {
            $modelClassName = 'App\\Models\\' . Str::before($file->getFilename(), '.php');

            if(in_array($modelClassName,['App\Models\ToyotaUkraineAPI'])) continue;

            if(!is_subclass_of($modelClassName,'App\Models\BaseModel')) continue;

            $model = new $modelClassName();

            $newColumns = [];
            $columns = $model->getFillable();

            foreach ($columns as $column) {

                $label = $column;

                if (str_ends_with($label, '_id')) {
                    $label = substr($label, 0, -3);
                }

                $label = str_replace('_', ' ', $label);

                $label = ucwords($label);

                $newColumns[] = $label;
            }

            $translatablePhrases = array_merge($translatablePhrases, $newColumns);

            $translatablePhrases = array_unique($translatablePhrases);
        }



        //$newTranslationsStr = "";
        $newTranslations = [];

        foreach ($translatablePhrases as $phrase) {
            if (!isset($uaTranslations[$phrase])) {

                //$translation = $this->translateWithChatGPT($phrase,'uk','en');
                $translation = $this->translateWithGoogle($phrase,'uk','en');

                $this->info('Translating: ' . $phrase . ' => '.$translation);

                $newTranslations[$phrase] = $translation;
            }
        }

        if (!empty($newTranslations)) {
            $uaTranslations = array_merge($uaTranslations, $newTranslations);
            File::put($uaJsonFile, json_encode($uaTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info(count($newTranslations) . ' new translations added to ua.json!');
        } else {
            $this->info('No new translations added.');
        }
    }

    private function translateWithGoogle(string $text, string $targetLanguage,$sourceLanguage = 'en')
    {
        $cacheKey = 'google-translate-' . $text . '-' . $targetLanguage;

        if (cache()->has($cacheKey)) {
            return cache()->get($cacheKey);
        }

        $translation = GoogleTranslate::trans($text, $targetLanguage, $sourceLanguage);
        if(strlen($translation)>0){
            cache()->put($cacheKey, $translation, 60*60*24*30);
            return $translation;
        }

        throw new \Exception('Translation failed for text: ' . $text);
    }
}
