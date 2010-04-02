<?php

class SphinxSearch_spell {

    var $string;            // what to check
    var $words;             // words from $string
    var $suggestion_needed; // is the suggestion needed
    var $suggestion;        // the actual suggestion

    function spell ($string) {
        $this->string = str_replace('"', '', $string);
        $this->words = preg_split('/(\s+|\|)/', $this->string, -1, PREG_SPLIT_NO_EMPTY);
        if (function_exists('pspell_check')) {
            $this->suggestion = $this->builtin_spell();
        } else {
            $this->suggestion = $this->nonnative_spell();
        }
        if ($this->suggestion_needed)
            return $this->suggestion;
        else
            return '';
    }

    function builtin_spell () {
        global $wgUser, $wgSphinxSearchPersonalDictionary, $wgSphinxSearchPspellDictionaryDir;

        $ret = '';
        $this->suggestion_needed = false;
        foreach ($this->words as $word) {
            $pspell_config = pspell_config_create(
                                  $wgUser->getDefaultOption('language'),
                                  $wgUser->getDefaultOption('variant'));
            if ($wgSphinxSearchPspellDictionaryDir) {
                pspell_config_data_dir($pspell_config, $wgSphinxSearchPspellDictionaryDir);
                pspell_config_dict_dir($pspell_config, $wgSphinxSearchPspellDictionaryDir);
            }
            pspell_config_mode($pspell_config, PSPELL_FAST|PSPELL_RUN_TOGETHER);
            if ($wgSphinxSearchPersonalDictionary)
                pspell_config_personal($pspell_config, $wgSphinxSearchPersonalDictionary);
            $pspell_link = pspell_new_config($pspell_config);
            
            if (!$pspell_link)
                return "Error starting pspell personal dictionary\n";

            if (!pspell_check($pspell_link, $word)) {
                $suggestions = pspell_suggest($pspell_link, $word);
                $guess = $this->bestguess($word, $suggestions);
                if (strtolower($word) == strtolower($guess)) {
                    $ret .= "$word ";
                } else {
                    $ret .=  "$guess ";
                    $this->suggestion_needed = true;
                }
                unset($suggestion);
                unset($guess);
            } else {
                $ret .= "$word ";
            }
        }

        unset($pspell_config);
        unset($pspell_link);
        return trim($ret);

    }

    function nonnative_spell () {
        global $wgUser, $wgSphinxSearchPersonalDictionary, $wgSphinxSearchAspellPath;

        // aspell will only return mis-spelled words, so remember all here
        $word_suggestions = array();
        foreach ($this->words as $word) {
            $word_suggestions[$word] = $word;
        }

        // prepare the system call with optional dictionary
        $aspellcommand = 'echo ' . escapeshellarg($this->string) .
                        ' | ' . escapeshellarg($wgSphinxSearchAspellPath) .
                        ' -a --ignore-accents --ignore-case';
        if ($wgUser) {
            $aspellcommand .= ' --lang='.$wgUser->getDefaultOption('language');
        }
        if ($wgSphinxSearchPersonalDictionary) {
            $aspellcommand .= ' --home-dir='.dirname($wgSphinxSearchPersonalDictionary);
            $aspellcommand .= ' -p '.basename($wgSphinxSearchPersonalDictionary);
        }

        // run aspell
        $shell_return = shell_exec($aspellcommand);

        // parse return line by line
        $returnarray = explode("\n", $shell_return);
        $this->suggestion_needed = false;
        foreach($returnarray as $key=>$value) {
            // lines with suggestions start with &
            if (substr($value, 0, 1) == "&") {
                $correction = explode(" ",$value);
                $word = $correction[1];
                $suggstart = strpos($value, ":") + 2;
                $suggestions = substr($value, $suggstart);
                $suggestionarray = explode(", ", $suggestions);
                $guess = $this->bestguess($word, $suggestionarray);
                
                if (strtolower($word) != strtolower($guess)) {
                    $word_suggestions[$word] = $guess;
                    $this->suggestion_needed = true;
                }
            }
        }

        return join(' ', $word_suggestions);
    }

    /* This function takes a word, and an array of suggested words
     * and figure out which suggestion is closest sounding to
     * the word. Thif is made possible with the use of the
     * levenshtein() function.
     */
    function bestguess($word, $suggestions) {
        $shortest = -1;

        if (preg_match('/^[^a-zA-Z]*$/', $word))
            return $word;

        foreach ($suggestions as $suggested) {
            $lev = levenshtein(strtolower($word), strtolower($suggested));
            if ($lev == 0) {
                // closest word is this one (exact match)
                $closest = $word;
                $shortest = 0;

                // break out of the loop; we've found an exact match
                break;
            }

            // if this distance is less than the next found shortest
            // distance, OR if a next shortest word has not yet been found
            if ($lev <= $shortest || $shortest < 0) {
                // set the closest match, and shortest distance
                $closest  = $suggested;
                $shortest = $lev;
            }
        }

        return $closest;
    }
}
