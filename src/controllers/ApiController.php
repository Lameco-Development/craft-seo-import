<?php

namespace lameco\seoimport\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\App;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

class ApiController extends Controller
{
    protected array|bool|int $allowAnonymous = ['import'];
    public $enableCsrfValidation = false;

    public function beforeAction($action): bool
    {
        $apiKey = App::env('SEO_IMPORT_API_KEY');

        if (!$apiKey) {
            throw new UnauthorizedHttpException('API key not configured');
        }

        $authHeader = $this->request->getHeaders()->get('Authorization');

        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $providedKey = substr($authHeader, 7);

            if (hash_equals($apiKey, $providedKey)) {
                return parent::beforeAction($action);
            }
        }

        throw new UnauthorizedHttpException('Authentication required');
    }

    public function actionImport(): Response
    {
        $this->requirePostRequest();

        $json = $this->request->getRawBody();

        if (empty($json)) {
            throw new BadRequestHttpException('No JSON data provided in request body.');
        }

        $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

        // Support multiple formats:
        // 1. Flat array: [{url, meta_title, ...}, ...]
        // 2. Wrapped: {"results": [{url, meta_title, ...}, ...]}
        // 3. Array-wrapped: [{"results": [...]}]
        if (is_array($data)) {
            if (isset($data[0]->results)) {
                $results = $data[0]->results;
            } else {
                $results = $data;
            }
        } else {
            $results = $data->results ?? null;
        }

        if (!is_array($results) || empty($results)) {
            throw new BadRequestHttpException('No results found in JSON data.');
        }

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $updated = 0;
        $skipped = [];

        foreach ($results as $item) {
            $url = $item->url ?? null;
            $metaTitle = $item->meta_title ?? null;
            $metaDescription = $item->meta_description ?? null;

            if (!$url) {
                continue;
            }

            if (empty($metaTitle) && empty($metaDescription)) {
                $skipped[] = ['url' => $url, 'reason' => 'No meta_title or meta_description provided'];
                continue;
            }

            $uri = $this->resolveUri($url);

            $entry = Entry::find()
                ->uri($uri ?: '__home__')
                ->siteId($siteId)
                ->one();

            if (!$entry) {
                $skipped[] = ['url' => $url, 'uri' => $uri, 'reason' => 'No entry found'];
                continue;
            }

            $seo = $entry->commonSeo ?? null;

            if (!$seo) {
                $skipped[] = ['url' => $url, 'reason' => 'No SEO field found on entry'];
                continue;
            }

            $changed = false;

            if ($metaTitle) {
                $seo->metaGlobalVars->overrides['seoTitle'] = true;
                $seo->metaGlobalVars->seoTitle = $metaTitle;
                $changed = true;
            }

            if ($metaDescription) {
                $seo->metaGlobalVars->overrides['seoDescription'] = true;
                $seo->metaGlobalVars->seoDescription = $metaDescription;
                $changed = true;
            }

            if (!$changed) {
                continue;
            }

            $entry->commonSeo = $seo;

            if (Craft::$app->elements->saveElement($entry)) {
                ++$updated;
            } else {
                $skipped[] = ['url' => $url, 'reason' => 'Failed to save entry'];
            }
        }

        return $this->asJson([
            'success' => true,
            'updated' => $updated,
            'total' => count($results),
            'skipped' => $skipped,
        ]);
    }

    private function resolveUri(string $url): string
    {
        // Strip protocol and domain, keep only the path
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $path = trim($path, '/');

        // Strip site base URL path prefix if present
        $sites = Craft::$app->getSites()->getAllSites();

        $sitePaths = [];
        foreach ($sites as $site) {
            $basePath = trim(parse_url($site->getBaseUrl(), PHP_URL_PATH) ?? '', '/');
            $sitePaths[] = $basePath;
        }

        // Sort by length descending so longer prefixes match first
        usort($sitePaths, fn($a, $b) => strlen($b) - strlen($a));

        foreach ($sitePaths as $basePath) {
            if ($basePath === '') {
                continue;
            }

            $prefix = $basePath . '/';
            if (str_starts_with($path, $prefix)) {
                return substr($path, strlen($prefix));
            }
            if ($path === $basePath) {
                return '';
            }
        }

        return $path;
    }
}
