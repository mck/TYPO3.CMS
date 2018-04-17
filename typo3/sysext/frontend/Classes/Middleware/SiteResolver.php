<?php
declare(strict_types = 1);
namespace TYPO3\CMS\Frontend\Middleware;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Identifies if a site is configured for the request, based on "id" and "L" GET/POST parameters, or the requested
 * string.
 *
 * If a site is found, the request is populated with the found language+site objects. If none is found, the main magic
 * is handled by the PageResolver middleware.
 *
 * In addition to that, TSFE gets the $domainStartPage information resolved and added.
 */
class SiteResolver implements MiddlewareInterface
{
    /**
     * Resolve the site/language information by checking the page ID or the URL.
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $finder = GeneralUtility::makeInstance(SiteFinder::class);

        $site = null;
        $language = null;

        $pageId = $request->getQueryParams()['id'] ?? $request->getParsedBody()['id'] ?? 0;
        $languageId = $request->getQueryParams()['L'] ?? $request->getParsedBody()['L'] ?? null;

        // 1. Check if we have a _GET/_POST parameter for "id", then a site information can be resolved based.
        if ($pageId > 0 && $languageId !== null) {
            // Loop over the whole rootline without permissions to get the actual site information
            try {
                $site = $finder->getSiteByPageId((int)$pageId);
                $language = $site->getLanguageById((int)$languageId);
            } catch (SiteNotFoundException $e) {
            }
        }
        if (!($language instanceof SiteLanguage)) {
            // 2. Check if there is a site language, if not, just don't do anything
            $language = $finder->getSiteLanguageByBase((string)$request->getUri());
            // @todo: use exception for getSiteLanguageByBase
            if ($language) {
                $site = $language->getSite();
            }
        }

        // Add language+site information to the PSR-7 request object.
        if ($language instanceof SiteLanguage && $site instanceof Site) {
            $request = $request->withAttribute('site', $site);
            $request = $request->withAttribute('language', $language);
            $queryParams = $request->getQueryParams();
            // necessary to calculate the proper hash base
            $queryParams['L'] = $language->getLanguageId();
            $request = $request->withQueryParams($queryParams);
            $_GET['L'] = $queryParams['L'];
            // At this point, we later get further route modifiers
            // for bw-compat we update $GLOBALS[TYPO3_REQUEST] to be used later in TSFE.
            $GLOBALS['TYPO3_REQUEST'] = $request;
        }

        // Now resolve the root page of the site, the page_id of the current domain
        if ($site instanceof Site) {
            $GLOBALS['TSFE']->domainStartPage = $site->getRootPageId();
        } else {
            $GLOBALS['TSFE']->domainStartPage = $this->findDomainRecord($request->getAttribute('normalizedParams'), (bool)$GLOBALS['TYPO3_CONF_VARS']['SYS']['recursiveDomainSearch']);
        }

        return $handler->handle($request);
    }

    /**
     * Looking up a domain record based on server parameters HTTP_HOST
     *
     * @param NormalizedParams $requestParams used to get sanitized information of the current request
     * @param bool $recursive If set, it looks "recursively" meaning that a domain like "123.456.typo3.com" would find a domain record like "typo3.com" if "123.456.typo3.com" or "456.typo3.com" did not exist.
     * @return int|null Returns the page id of the page where the domain record was found or null if no sys_domain record found.
     * previously found at \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController::findDomainRecord()
     */
    protected function findDomainRecord(NormalizedParams $requestParams, $recursive = false): ?int
    {
        if ($recursive) {
            $pageUid = 0;
            $host = explode('.', $requestParams->getHttpHost());
            while (count($host)) {
                $pageUid = $this->getRootPageIdFromDomainRecord(implode('.', $host), $requestParams->getScriptName());
                if ($pageUid) {
                    return $pageUid;
                }
                array_shift($host);
            }
            return $pageUid;
        }
        return $this->getRootPageIdFromDomainRecord($requestParams->getHttpHost(), $requestParams->getScriptName());
    }

    /**
     * Will find the page ID carrying the domain record matching the input domain.
     *
     * @param string $domain Domain name to search for. Eg. "www.typo3.com". Typical the HTTP_HOST value.
     * @param string $path Path for the current script in domain. Eg. "/somedir/subdir". Typ. supplied by \TYPO3\CMS\Core\Utility\GeneralUtility::getIndpEnv('SCRIPT_NAME')
     * @return int|null If found, returns integer with page UID where found. Otherwise null.
     * previously found at PageRepository::getDomainStartPage
     */
    protected function getRootPageIdFromDomainRecord(string $domain, string $path = ''): ?int
    {
        list($domain) = explode(':', $domain);
        $domain = strtolower(preg_replace('/\\.$/', '', $domain));
        // Removing extra trailing slashes
        $path = trim(preg_replace('/\\/[^\\/]*$/', '', $path));
        // Appending to domain string
        $domain .= $path;
        $domain = preg_replace('/\\/*$/', '', $domain);
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_domain');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select(
                'pid'
            )
            ->from('sys_domain')
            ->where(
                $queryBuilder->expr()->eq(
                    'hidden',
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->eq(
                        'domainName',
                        $queryBuilder->createNamedParameter($domain, \PDO::PARAM_STR)
                    ),
                    $queryBuilder->expr()->eq(
                        'domainName',
                        $queryBuilder->createNamedParameter($domain . '/', \PDO::PARAM_STR)
                    )
                )
            )
            ->setMaxResults(1)
            ->execute()
            ->fetch();
        return $row ? (int)$row['pid'] : null;
    }
}
