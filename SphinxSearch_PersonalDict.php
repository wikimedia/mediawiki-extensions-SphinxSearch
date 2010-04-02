<?php

/**
 * SphinxSearch extension code for MediaWiki
 *
 * http://www.mediawiki.org/wiki/Extension:SphinxSearch
 *
 * Developed by Paul Grinberg and Svemir Brkic
 *
 * Released under GNU General Public License (see http://www.fsf.org/licenses/gpl.html)
 *
 */

class SphinxSearchPersonalDict extends SpecialPage {

    function SphinxSearchPersonalDict() {
        SpecialPage::SpecialPage("SphinxSearchPersonalDict", 'delete');
        self::loadMessages();
        return true;
    }

    function loadMessages() {
        static $messagesLoaded = false;
        global $wgMessageCache;
        if ($messagesLoaded) {
            return;
        }
        $messagesLoaded = true;

        $allMessages = array(
            'en' => array(
                'sphinxsearchpersonaldict' => 'Wiki-specific Sphinx search spellcheck dictionary',
                'sphinxsearchindictionary' => 'Already in personal dictionary',
                'sphinxsearchtobeadded'    => 'To be added to personal dictionary',
                'sphinxsearchnotadded'     => "Word '''%s''' was not added to dictionary because it contained non alphabetic characters",
                'sphinxsearchcantpersonaldict' => 'You are not allowed to modify the {{SITENAME}} specific dictionary',
            )
        );

        foreach ( $allMessages as $lang => $langMessages ) {
            $wgMessageCache->addMessages( $langMessages, $lang );
        }
        return true;
    }

    function execute($par) {
        global $wgRequest, $wgOut, $wgUser;

        $this->setHeaders();
        $wgOut->setPagetitle(wfMsg('sphinxsearchpersonaldict'));

        if (!$wgUser->isAllowed("delete")) {
            $wgOut->addWikiText(wfMsg('sphinxsearchcantpersonaldict'));
            $wgOut->addWikiText('----');
        }

        $toberemoved = $wgRequest->getArray('indictionary', array());
        $tobeadded   = $wgRequest->getVal('tobeadded','');
        $tobeadded   = preg_split('/\s/', trim($tobeadded), -1, PREG_SPLIT_NO_EMPTY);

        $this->deleteFromPersonalDictionary($toberemoved);
        $this->addToPersonalDictionary($tobeadded);

        $this->CreateForm($wgUser->isAllowed("delete"));
    }

    function CreateForm($allowed_to_add) {
        global $wgOut;
        global $wgSphinxSearchPersonalDictionary;
        
        $wgOut->addHTML("<form method=post>");
        $wgOut->addHTML("<div style=\"border: thin solid #000000; width:90%;\"><table cellpadding=\"15\" width=\"100%\" cellspacing=\"0\" border=\"0\">");
        $wgOut->addHTML("<tr><td valign=top>");
        $wgOut->addWikiText("<center>'''" . wfMsg('sphinxsearchindictionary') . "'''</center><p>");
        $wgOut->addHTML('<select name="indictionary[]" size="15" multiple="multiple">');

        if (file_exists($wgSphinxSearchPersonalDictionary)) {
            $this->readPersonalDictionary($langauge, $numwords, $words);
            sort($words);

				if (sizeof($words)>0) {
              foreach ($words as $w)
                  $wgOut->addHTML("<option value='$w'>$w</option>");
            } else {
                $wgOut->addHTML("<option disabled value=''>Dictionary empty</option>");
            }
        } else {
            $wgOut->addHTML("<option disabled value=''>Dictionary not found</option>");
        }

        $wgOut->addHTML('</select></td><td valign=top>');
        if ($allowed_to_add) {
            $wgOut->addWikiText("<center>'''" . wfMsg('sphinxsearchtobeadded') . "'''</center><p>");
            $wgOut->addHTML("<textarea name=\"tobeadded\" cols=\"30\" rows=\"15\"></textarea>");
            $wgOut->addHTML('</td></tr><tr><td colspan=2>');
            $wgOut->addHTML("<center><input type=\"submit\" value=\"Execute\" /></center>");
        }
        $wgOut->addHTML("</td></tr></table></div></form>");
    }

    function addToPersonalDictionary($list) {
        if (function_exists('pspell_config_create')) {
            $this->builtin_addword($list);
        } else {
            $this->nonnative_addword($list);
        }
    }

    function getSearchLanguage() {
      global $wgUser, $wgLanguageCode;
      
      // Try to read the default language from $wgUser: 
      $language = trim($wgUser->getDefaultOption('language'));

      // Use global variable: $wgLanguageCode (from LocalSettings.php) as fallback:
      if (empty($language)) { $language = trim($wgLanguageCode); }

      // If we still don't have a valid language yet, assume English:
      if (empty($language)) { $language = 'en'; }

		return $language;
    }

    function builtin_addword($list) {
        global $wgUser, $wgOut;
        global $wgSphinxSearchPersonalDictionary;
        global $wgSphinxSearchPspellDictionaryDir;

        $language = $this->getSearchLanguage();
        
        $pspell_config = pspell_config_create(
                                $language,
                                $wgUser->getDefaultOption('variant'));
        if ($wgSphinxSearchPspellDictionaryDir) {
            pspell_config_data_dir($pspell_config, $wgSphinxSearchPspellDictionaryDir);
            pspell_config_dict_dir($pspell_config, $wgSphinxSearchPspellDictionaryDir);
        }
        pspell_config_mode($pspell_config, PSPELL_FAST|PSPELL_RUN_TOGETHER);
        if ($wgSphinxSearchPersonalDictionary)
            pspell_config_personal($pspell_config, $wgSphinxSearchPersonalDictionary);
        $pspell_link = pspell_new_config($pspell_config);
        
        $write_needed = false;
        foreach ($list as $word) {
            if ($word == '')
                continue;
            if (preg_match('/[^a-zA-Z]/', $word)) {
                $wgOut->addWikiText(sprintf(wfMsg('sphinxsearchnotadded'), $word));
                continue;
            }
            pspell_add_to_personal($pspell_link, $word);
            $write_needed = true;
        }

        if ($write_needed) {
            pspell_save_wordlist($pspell_link);
        }
    }

    function nonnative_addword($list) {
        global $wgUser;
        global $wgSphinxSearchPersonalDictionary;

        if (!file_exists($wgSphinxSearchPersonalDictionary)) {
            // create the personal dictionary file if it does not already exist
            $language = $this->getSearchLanguage();
            $numwords = 0;
            $words = array();
        } else {
            $this->readPersonalDictionary($language, $numwords, $words);
        }

        $write_needed = false;
        foreach ($list as $word) {
            if (!in_array($word, $words)) {
                $numwords++;
                array_push($words, $word);
                $write_needed = true;
            }
        }

        if ($write_needed)
            $this->writePersonalDictionary($language, $numwords, $words);
    }

    function writePersonalDictionary($language, $numwords, $words) {
        global $wgSphinxSearchPersonalDictionary;

        $handle = fopen($wgSphinxSearchPersonalDictionary, "wt");
        if ($handle) {
            fwrite($handle, "personal_ws-1.1 $language $numwords\n");
            foreach ($words as $w) {
                fwrite($handle, "$w\n");
            }
            fclose($handle);
        }
    }

    function readPersonalDictionary(&$language, &$numwords, &$words) {
        global $wgSphinxSearchPersonalDictionary;

        $words = array();
        $lines = explode("\n", file_get_contents($wgSphinxSearchPersonalDictionary));
        foreach ($lines as $line) {
            trim($line);
            if (preg_match('/\s(\w+)\s(\d+)/', $line, $matches)) {
                $language = $matches[1];
                $numwords = $matches[2];
            } else
                if ($line)
                    array_push($words, $line);
        }

        // Make sure that we have a valid value for language if it wasn't in the .pws file:
        if (empty($language)) { $language = $this->getSearchLanguage(); }
    }

    function deleteFromPersonalDictionary($list) {
        // there is no built in way to delete from the personal dictionary.
        
        $this->readPersonalDictionary($language, $numwords, $words);
        
        $write_needed = false;
        foreach ($list as $w) {
            if ($w == '')
                continue;
            if (in_array($w, $words)) {
                $index = array_keys($words, $w);
                unset($words[$index[0]]);
                $numwords--;
                $write_needed = true;
            }
        }
        
        if ($write_needed)
            $this->writePersonalDictionary($language, $numwords, $words);
    }
}
