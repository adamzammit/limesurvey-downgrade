<?php

if (isset($_FILES['userfile'])) {
    if (is_uploaded_file($_FILES['userfile']['tmp_name'])) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $ftype = finfo_file($finfo,$_FILES['userfile']['tmp_name']);
        if ($ftype == "text/xml") {
            include_once("limesurvey-downgrade.php");
            $lsd = new LimeSurveyDowngrade();
            $r = $lsd->loadfile($_FILES['userfile']['tmp_name']);
            if ($r) {
                $xml = $lsd->downgrade();
                if ($xml !== false) {
                    header('Content-Type: text/xml');
                    header('Content-Disposition: attachment; filename="ls3export.lss"');
                    echo $xml;
                }
            }
        }
    }
}
