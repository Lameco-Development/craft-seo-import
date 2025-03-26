<?php

namespace lameco\seoimport\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use craft\web\Controller;
use craft\web\Request;
use craft\web\UploadedFile;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

class ImportController extends Controller
{
    protected array|bool|int $allowAnonymous = [];

    public function actionImport(Request $request)
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $file = UploadedFile::getInstanceByName('file');

        if (!$file) {
            $this->setFailFlash(Craft::t('formie', 'An error occurred.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'importError' => Craft::t('formie', 'You must upload a file.'),
            ]);

            return null;
        }

        $siteId = $request->getBodyParam('siteId');
        $overwriteMetaTitle = (bool)$request->getBodyParam('overwriteMetaTitle', false);
        $overwriteMetaDescription = (bool)$request->getBodyParam('overwriteMetaDescription', false);

        $reader = new Xlsx();

        $spreadsheet = $reader->load($file->tempName);
        $worksheet = $spreadsheet->getActiveSheet();

        $rows = $worksheet->toArray();
        $headings = array_shift($rows);

        // Add headings as keys to each row
        array_walk(
            $rows,
            function(&$row) use ($headings) {
                $row = array_combine($headings, $row);
            }
        );

        $count = 0;

        foreach ($rows as $row) {
            $url = $row['Pagina URL'] ?? null;
            $metaTitle = $row['SEO titel'] ?? null;
            $metaDescription = $row['SEO beschrijving'] ?? null;

            if (empty($metaTitle) && empty($metaDescription)) {
                continue;
            }

            $slug = StringHelper::basename(parse_url($url, PHP_URL_PATH));

            $entry = Entry::find()
                ->slug($slug)
                ->siteId($siteId)
                ->one();

            if (!$entry) {
                $this->setFailFlash('Entry not found for ' . $url);
                continue;
            }

            $seo = $entry->commonSeo;

            if (!$seo) {
                $this->setFailFlash('SEO not found for ' . $url);
                continue;
            }

            if ($metaTitle && ($overwriteMetaTitle || !$seo->metaGlobalVars->seoTitle)) {
                $seo->metaGlobalVars->overrides['seoTitle'] = true;
                $seo->metaGlobalVars->seoTitle = $metaTitle;
            }

            if ($metaDescription && ($overwriteMetaDescription || !$seo->metaGlobalVars->seoDescription)) {
                $seo->metaGlobalVars->overrides['seoDescription'] = true;
                $seo->metaGlobalVars->seoDescription = $metaDescription;
            }

            $entry->commonSeo = $seo;

            if (Craft::$app->elements->saveElement($entry)) {
                ++$count;
            }
        }

        $this->setSuccessFlash('Updated SEO content for ' . $count . ' entries');
    }
}
