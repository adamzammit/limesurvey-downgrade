<?php

/**
 * Tool to convert a LimeSurvey LSS file from version 6 to version 3
 * php version 8.1
 *
 * @category Converter
 * @package  LimeSurvey
 * @author   Adam Zammit <adam.zammit@acspri.org.au>
 * @license  GPLv3 https://www.gnu.org/licenses/gpl-3.0.en.html
 * @link     https://www.github.com/adamzammit/limesurvey-downgrade
 */


class SimpleXMLElementExtended extends SimpleXMLElement {

    /**
     * Adds a child with $value inside CDATA
     * @param unknown $name
     * @param unknown $value
     */
    public function addChildWithCDATA($name, $value = null)
    {
        $new_child = $this->addChild($name);

        if ($new_child !== null) {
            $node = dom_import_simplexml($new_child);
            $no   = $node->ownerDocument;
            $node->appendChild($no->createCDATASection($value));
        }

        return $new_child;
    }
}

class LimeSurveyDowngrade {

    private $xml = null;

    public function __construct($file = null)
    {
        if ($file !== null) {
            $this->loadfile($file);
        }
    }

    public function loadfile($file)
    {
        if (file_exists($file)) {
            $xml = simplexml_load_file($file, 'SimpleXMLElementExtended');
            if ($xml !== false) {
                $this->xml = $xml;
                return true;
            }
        }
        return false;
    }

    public function downgrade()
    {
        if ($this->xml === null) {
            return false;
        }

        $xml = $this->xml;

        if (!isset($xml->DBVersion)) {
            die("Not a valid LSS file");
        }

        $version = (string) $xml->DBVersion;

        if ($version < 400) {
            die("This LSS file should already be compatible with LS3");
        }

        //find all languages

        $langs = [];
        foreach ($xml->languages->language as $f => $v) {
            $langs[(string) $v] = (string) $v;
        }

        //get default language
        $defaultlang = "en";

        $xml->DBVersion = 366;

        //make array of answer_l10ns for first language
        $ar = [];
        foreach ($langs as $lang) {
            if (isset($xml->answer_l10ns->rows)) {
                foreach ($xml->answer_l10ns->rows->row as $r) {
                    if ((string) $r->language == $lang) {
                        $ar[$lang][(string) $r->aid] = (string) $r->answer;
                    }
                }
            }
        }

        unset($xml->answer_l10ns);

        $gr = [];
        foreach ($langs as $lang) {
            foreach ($xml->group_l10ns->rows->row as $r) {
                if ((string) $r->language == $lang) {
                    $gr[$lang][(string) $r->gid] = [
                        'group_name' => (string) $r->group_name,
                        'description' => (string) $r->description
                    ];
                }
            }
        }

        unset($xml->group_l10ns);

        $qr = [];
        foreach ($langs as $lang) {
            foreach ($xml->question_l10ns->rows->row as $r) {
                if ((string) $r->language == $lang) {
                    $qr[$lang][(string) $r->qid] = [
                        'question' => (string) $r->question,
                        'help' => (string) $r->help
                    ];
                }
            }
        }

        unset($xml->question_l10ns);


        //regen answers
        if (isset($xml->answers->fields)) {
            $xml->answers->fields->addChild('fieldname', 'answer');
            $xml->answers->fields->addChild('fieldname', 'language');
        }
        $fields = $xml->xpath("/document/answers/fields[fieldname='aid']");

        unset($fields[0]->fieldname[0]);

        if (isset($xml->answers->rows)) {
            $rows = [];
            $lc = 1;
            foreach ($langs as $lang) {
                if ($lc == 1) {
                    foreach ($xml->answers->rows->row as $r) {
                        $rows[(string) $r->aid] = $r;
                        if (!isset($r->language)) {
                            $r->language = $lang;
                            $r->addChildWithCData('answer', $ar[$lang][(string) $r->aid]);
                            unset($r->aid);
                        }
                    }
                } else {
                    foreach ($rows as $key => $r) {
                        $nr = $xml->answers->rows->addChild('row');
                        $nr->addChildWithCData('qid', (string) $r->qid);
                        $nr->addChildWithCData('code', (string) $r->code);
                        $nr->addChildWithCData('sortorder', (string) $r->sortorder);
                        $nr->addChildWithCData('answer', $ar[$lang][$key]);
                        $nr->addChild('language', $lang);
                        $nr->addChildWithCData('assessment_value', (string) $r->assessment_value);
                        $nr->addChildWithCData('scale_id', (string) $r->scale_id);
                    }
                }
                $lc++;
            }
        }
        //regen groups

        $xml->groups->fields->addChild('fieldname', 'group_name');
        $xml->groups->fields->addChild('fieldname', 'description');
        $xml->groups->fields->addChild('fieldname', 'language');

        foreach ($xml->groups->rows as $xr) {
            $rows = [];
            $lc = 1;
            foreach ($langs as $lang) {
                if ($lc == 1) {
                    foreach ($xml->groups->rows->row as $r) {
                        $rows[(string) $r->gid] = $r;
                        if (!isset($r->language)) {
                            $r->language = $lang;
                            $r->addChildWithCData('group_name', $gr[$lang][(string) $r->gid]['group_name']);
                            $r->addChildWithCData('description', $gr[$lang][(string) $r->gid]['description']);
                        }
                    }
                } else {
                    foreach ($rows as $key => $r) {
                        $nr = $xml->groups->rows->addChild('row');
                        $nr->addChildWithCData('gid', (string) $r->gid);
                        $nr->addChildWithCData('sid', (string) $r->sid);
                        $nr->addChildWithCData('group_order', (string) $r->group_order);
                        $nr->addChildWithCData('randomization_group', (string) $r->randomization_group);
                        $nr->addChildWithCData('grelevance', (string) $r->grelevance);
                        $nr->addChildWithCData('group_name', $gr[$lang][$key]['group_name']);
                        $nr->addChildWithCData('description', $gr[$lang][$key]['description']);
                        $nr->addChild('language', $lang);
                    }
                }
                $lc++;
            }
        }

        //regen questions

        $fields = $xml->xpath("/document/questions/fields[fieldname='encrypted']");

        $exclude = ["encrypted","question_theme_name","same_script"];
        $excludekeys = [];
        $cnt = 0;
        foreach ($fields[0]->fieldname as $key => $val) {
            if (in_array($val, $exclude)) {
                $excludekeys[] = $cnt;
            }
            $cnt++;
        }

        arsort($excludekeys);

        foreach ($excludekeys as $key => $val) {
            unset($fields[0]->fieldname[$val]);
        }

        $xml->questions->fields->addChild('fieldname', 'question');
        $xml->questions->fields->addChild('fieldname', 'help');
        $xml->questions->fields->addChild('fieldname', 'language');


        foreach ($xml->questions->rows as $xr) {
            $rows = [];
            $lc = 1;
            foreach ($langs as $lang) {
                if ($lc == 1) {
                    foreach ($xml->questions->rows->row as $r) {
                        $rows[(string) $r->qid] = $r;
                        if (!isset($r->language)) {
                            $r->language = $lang;
                            $r->addChildWithCData('question', $qr[$lang][(string) $r->qid]['question']);
                            $r->addChildWithCData('help', $qr[$lang][(string) $r->qid]['help']);
                            unset($r->encrypted);
                            unset($r->question_theme_name);
                            unset($r->same_script);
                        }
                    }
                } else {
                    foreach ($rows as $key => $r) {
                        $nr = $xml->questions->rows->addChild('row');
                        $nr->addChildWithCData('qid', (string) $r->qid);
                        $nr->addChildWithCData('parent_qid', (string) $r->parent_qid);
                        $nr->addChildWithCData('sid', (string) $r->sid);
                        $nr->addChildWithCData('gid', (string) $r->gid);
                        $nr->addChildWithCData('type', (string) $r->type);
                        $nr->addChildWithCData('title', (string) $r->title);
                        $nr->addChildWithCData('preg', (string) $r->preg);
                        $nr->addChildWithCData('other', (string) $r->other);
                        $nr->addChildWithCData('mandatory', (string) $r->mandatory);
                        $nr->addChildWithCData('question_order', (string) $r->question_order);
                        $nr->addChildWithCData('scale_id', (string) $r->scale_id);
                        $nr->addChildWithCData('same_default', (string) $r->same_default);
                        $nr->addChildWithCData('relevance', (string) $r->relevance);
                        $nr->addChildWithCData('modulename', (string) $r->modulename);
                        $nr->addChildWithCData('question', $qr[$lang][$key]['question']);
                        $nr->addChildWithCData('help', $qr[$lang][$key]['help']);
                        $nr->addChild('language', $lang);
                    }
                }
                $lc++;
            }
        }

        //regen subquestionsquestions

        $fields = $xml->xpath("/document/subquestions/fields[fieldname='encrypted']");

        if (isset($fields[0])) {
            $exclude = ["encrypted","question_theme_name","same_script"];
            $excludekeys = [];
            $cnt = 0;
            foreach ($fields[0]->fieldname as $key => $val) {
                if (in_array($val, $exclude)) {
                    $excludekeys[] = $cnt;
                }
                $cnt++;
            }

            arsort($excludekeys);

            foreach ($excludekeys as $key => $val) {
                unset($fields[0]->fieldname[$val]);
            }
        }

        if (isset($xml->subquestions->fields)) {
            $xml->subquestions->fields->addChild('fieldname', 'question');
            $xml->subquestions->fields->addChild('fieldname', 'help');
            $xml->subquestions->fields->addChild('fieldname', 'language');
        }

        if (isset($xml->subquestions->rows)) {
            foreach ($xml->subquestions->rows as $xr) {
                $rows = [];
                $lc = 1;
                foreach ($langs as $lang) {
                    if ($lc == 1) {
                        foreach ($xml->subquestions->rows->row as $r) {
                            $rows[(string) $r->qid] = $r;
                            if (!isset($r->language)) {
                                $r->language = $lang;
                                $r->addChildWithCData('question', $qr[$lang][(string) $r->gid]['question']);
                                $r->addChildWithCData('help', $qr[$lang][(string) $r->gid]['help']);
                                unset($r->encrypted);
                                unset($r->question_theme_name);
                                unset($r->same_script);
                            }
                        }
                    } else {
                        foreach ($rows as $key => $r) {
                            $nr = $xml->subquestions->rows->addChild('row');
                            $nr->addChildWithCData('qid', (string) $r->qid);
                            $nr->addChildWithCData('parent_qid', (string) $r->parent_qid);
                            $nr->addChildWithCData('sid', (string) $r->sid);
                            $nr->addChildWithCData('gid', (string) $r->gid);
                            $nr->addChildWithCData('type', (string) $r->type);
                            $nr->addChildWithCData('title', (string) $r->title);
                            $nr->addChildWithCData('preg', (string) $r->preg);
                            $nr->addChildWithCData('other', (string) $r->other);
                            $nr->addChildWithCData('mandatory', (string) $r->mandatory);
                            $nr->addChildWithCData('question_order', (string) $r->question_order);
                            $nr->addChildWithCData('scale_id', (string) $r->scale_id);
                            $nr->addChildWithCData('same_default', (string) $r->same_default);
                            $nr->addChildWithCData('relevance', (string) $r->relevance);
                            $nr->addChildWithCData('modulename', (string) $r->modulename);
                            $nr->addChildWithCData('question', $qr[$lang][$key]['question']);
                            $nr->addChildWithCData('help', $qr[$lang][$key]['help']);
                            $nr->addChild('language', $lang);
                        }
                    }
                    $lc++;
                }
            }
        }

        //remove tokenencryptionoptions from surveys/fields

        $fields = $xml->xpath("/document/surveys/fields[fieldname='tokenencryptionoptions']");

        $exclude = ["tokenencryptionoptions"];
        $excludekeys = [];
        $cnt = 0;
        foreach ($fields[0]->fieldname as $key => $val) {
            if (in_array($val, $exclude)) {
                $excludekeys[] = $cnt;
            }
            $cnt++;
        }

        arsort($excludekeys);

        foreach ($excludekeys as $key => $val) {
            unset($fields[0]->fieldname[$val]);
        }

        //remove all surveys rows where value == inherit

        foreach ($xml->surveys->rows->row as $r) {
            $ulist = [];
            foreach ($r as $a => $v) {
                if ((string) $v == "I" || (string) $v == "inherit" || (string) $v == "-1" || (string) $v == "E" || (string) $a == "tokenencryptionoptions") {
                    $ulist[] = $a;
                }
            }
            foreach ($ulist as $a) {
                unset($r->$a);
            }
        }

        return $xml->asXML();
    }
}
