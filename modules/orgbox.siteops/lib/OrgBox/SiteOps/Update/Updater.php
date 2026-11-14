<?php

declare(strict_types=1);

namespace OrgBox\SiteOps\Update;

/** Проверка и установка обновлений из GitHub Releases. Ничего не делает сам по себе: запускается администратором. */
final class Updater
{
    public function __construct(
        private HttpClient $http,
        private ReleaseVerifier $verifier,
        private ZipInstaller $installer,
        private PackageProfile $profile,
        private string $repository,
        private string $githubToken,
        private string $moduleDir,
        private string $workDir,
        private string $currentVersion,
    ) {
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$#', $repository)) {
            throw new UpdateException('Некорректное имя репозитория обновлений.');
        }
    }

    public function currentVersion(): string
    {
        return $this->currentVersion;
    }

    /** @return ReleaseInfo|null null, если установлена последняя версия */
    public function checkLatest(): ?ReleaseInfo
    {
        $json = $this->http->get(
            'https://api.github.com/repos/' . $this->repository . '/releases/latest',
            $this->apiHeaders('application/vnd.github+json'),
            1048576,
        );
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['tag_name'])) {
            throw new UpdateException('Ответ GitHub не распознан.');
        }
        $version = ltrim((string) $data['tag_name'], 'vV');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new UpdateException('Тег релиза не похож на версию: ' . $data['tag_name']);
        }
        if (version_compare($version, $this->currentVersion, '<=')) {
            return null;
        }

        $assets = [];
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            $assets[(string) ($asset['name'] ?? '')] = $asset;
        }
        $zipName = $this->profile->zipName($version);
        if (!isset($assets[$zipName], $assets[$zipName . '.sha256'])) {
            throw new UpdateException('В релизе нет архива или контрольной суммы (' . $zipName . ').');
        }
        $viaApi = $this->githubToken !== '';
        $url = static fn (array $asset): string => (string) ($viaApi ? ($asset['url'] ?? '') : ($asset['browser_download_url'] ?? ''));

        return new ReleaseInfo(
            $version,
            $url($assets[$zipName]),
            $url($assets[$zipName . '.sha256']),
            isset($assets[$zipName . '.sig']) ? $url($assets[$zipName . '.sig']) : null,
            mb_substr((string) ($data['body'] ?? ''), 0, 4000),
            $viaApi,
        );
    }

    /** @return string способ проверки: signed или checksum-only */
    public function install(ReleaseInfo $release): string
    {
        $headers = $this->apiHeaders($release->viaApi ? 'application/octet-stream' : '*/*');
        $zip = $this->http->get($release->zipUrl, $headers, 20971520);
        $sha = $this->http->get($release->sha256Url, $headers, 4096);
        $signature = $release->signatureUrl !== null ? $this->http->get($release->signatureUrl, $headers, 4096) : null;

        $method = $this->verifier->verify($zip, $sha, $signature);

        if (!is_dir($this->workDir) && !mkdir($this->workDir, 0755, true) && !is_dir($this->workDir)) {
            throw new UpdateException('Не удалось создать рабочую папку обновлений.');
        }
        $zipPath = $this->workDir . '/download-' . $release->version . '.zip';
        if (file_put_contents($zipPath, $zip) === false) {
            throw new UpdateException('Не удалось сохранить архив.');
        }
        try {
            $this->installer->install($zipPath, $this->moduleDir, $this->workDir, $release->version, $this->currentVersion);
        } finally {
            @unlink($zipPath);
        }

        return $method;
    }

    /** @return array<string,string> */
    private function apiHeaders(string $accept): array
    {
        $headers = ['Accept' => $accept];
        if ($this->githubToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->githubToken;
        }

        return $headers;
    }
}
