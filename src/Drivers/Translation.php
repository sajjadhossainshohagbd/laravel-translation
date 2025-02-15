<?php

namespace JoeDixon\Translation\Drivers;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use JoeDixon\Translation\Events\TranslationAdded;
use Stichoza\GoogleTranslate\GoogleTranslate;

abstract class Translation
{
    /**
     * Find all of the translations in the app without translation for a given language.
     *
     * @param  string  $language
     * @return array
     */
    public function findMissingTranslations($language)
    {
        return array_diff_assoc_recursive(
            $this->scanner->findTranslations(),
            $this->allTranslationsFor($language)
        );
    }

    /**
     * Save all of the translations in the app without translation for a given language.
     *
     * @param  string  $language
     * @return void
     */
    public function saveMissingTranslations($language = false)
    {
        $languages = $language ? [$language => $language] : $this->allLanguages();

        foreach ($languages as $language => $name) {
            $missingTranslations = $this->findMissingTranslations($language);

            foreach ($missingTranslations as $type => $groups) {
                foreach ($groups as $group => $translations) {
                    foreach ($translations as $key => $value) {
                        if (Str::contains($group, 'single')) {
                            $this->addSingleTranslation($language, $group, $key);
                        } else {
                            $this->addGroupTranslation($language, $group, $key);
                        }
                    }
                }
            }
        }
    }

    /**
     * Save all of the translations in the app without translation for a given language then
     * Translate all the tokens into it's respective language using google translate
     *
     * @param  string  $language
     * @return void
     */
    public function autoTranslate($language = false)
    {
        $languages = $language ? [$language => $language] : $this->allLanguages();

        foreach ($languages as $language => $name) {
            $this->saveMissingTranslations($language);
            $this->translateLanguage($language);
        }
    }

    /**
     *
     * Translate text using Google Translate
     *
     * @param $language
     * @param $token
     * @return string|null
     * @throws \ErrorException
     */
    public function getGoogleTranslate($language,$token){
        $tr = new GoogleTranslate($language);
        return $tr->translate($token);
    }

    /**
     * Loop through all the keys and get translated text from Google Translate
     *
     * @param $language
     */
    public function translateLanguage($language){
        //No need to translate e.g. English to English
        if ($language === $this->sourceLanguage) {
            return;
        }

        $translations = $this->getSourceLanguageTranslationsWith($language);

        foreach ($translations as $type => $groups) {
            foreach ($groups as $group => $translations) {
                foreach ($translations as $key => $value) {
                    $sourceLanguageValue = in_array($value[$this->sourceLanguage], ["", null]) ? $key : $value[$this->sourceLanguage];
                    $targetLanguageValue = $value[$language];

                    if (in_array($targetLanguageValue, ["", null])) {
                        $new_value = $this->getGoogleTranslate($language, $sourceLanguageValue);
                        if (Str::contains($group, 'single')) {
                            $this->addSingleTranslation($language, $group, $key, $new_value);
                        } else {
                            $this->addGroupTranslation($language, $group, $key, $new_value);
                        }
                    }

                }
            }
        }
    }

    /**
     * Get all translations for a given language merged with the source language.
     *
     * @param  string  $language
     * @return Collection
     */
    public function getSourceLanguageTranslationsWith($language)
    {
        $sourceTranslations = $this->allTranslationsFor($this->sourceLanguage);
        $languageTranslations = $this->allTranslationsFor($language);

        return $sourceTranslations->map(function ($groups, $type) use ($language, $languageTranslations) {
            return $groups->map(function ($translations, $group) use ($type, $language, $languageTranslations) {
                $translations = $translations->toArray();
                array_walk($translations, function (&$value, $key) use ($type, $group, $language, $languageTranslations) {
                    $value = [
                        $this->sourceLanguage => $value,
                        $language => $languageTranslations->get($type, collect())->get($group, collect())->get($key),
                    ];
                });

                return $translations;
            });
        });
    }

    /**
     * Filter all keys and translations for a given language and string.
     *
     * @param  string  $language
     * @param  string  $filter
     * @return Collection
     */
    public function filterTranslationsFor($language, $filter)
    {
        $allTranslations = $this->getSourceLanguageTranslationsWith($language);
        if (! $filter) {
            return $allTranslations;
        }

        return $allTranslations->map(function ($groups, $type) use ($language, $filter) {
            return $groups->map(function ($keys, $group) use ($language, $filter) {
                return collect($keys)->filter(function ($translations, $key) use ($group, $language, $filter) {
                    return strs_contain([$group, $key, $translations[$language], $translations[$this->sourceLanguage]], $filter);
                });
            })->filter(function ($keys) {
                return $keys->isNotEmpty();
            });
        });
    }

    public function add(Request $request, $language, $isGroupTranslation)
    {
        $namespace = $request->has('namespace') && $request->get('namespace') ? "{$request->get('namespace')}::" : '';
        $group = $namespace.$request->get('group');
        $key = $request->get('key');
        $value = $request->get('value') ?: '';

        if ($isGroupTranslation) {
            $this->addGroupTranslation($language, $group, $key, $value);
        } else {
            $this->addSingleTranslation($language, 'single', $key, $value);
        }

        Event::dispatch(new TranslationAdded($language, $group ?: 'single', $key, $value));
    }
}
