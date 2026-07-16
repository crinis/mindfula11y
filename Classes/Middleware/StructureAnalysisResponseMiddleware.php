<?php
declare(strict_types=1);

/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

namespace MindfulMarkup\MindfulA11y\Middleware;

use MindfulMarkup\MindfulA11y\Domain\Model\StructureAnalysisTicket;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\CMS\Core\Routing\PageArguments;

/** Converts a validated frontend response into an isolated iframe analysis document. */
final readonly class StructureAnalysisResponseMiddleware implements MiddlewareInterface
{
    use HtmlResponseTrait;

    public function __construct(
        private StreamFactoryInterface $streamFactory,
        private StructureAnalysisResponseHardener $hardener,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ticket = StructureAnalysisTicket::fromRequest($request);
        if ($ticket === null) {
            return $handler->handle($request);
        }

        $routing = $request->getAttribute('routing');
        if (!$routing instanceof PageArguments || $routing->getPageId() !== $ticket->pageId) {
            // The signed ticket authorizes exactly one page. Any other resolved
            // route is rejected before frontend controllers, content objects or
            // plugins can execute with the ticket's preview visibility.
            $response = $this->hardener->createMinimalHtmlResponse(403);
        } else {
            $response = $handler->handle($request);
        }

        $redirectStatus = $response->getStatusCode();
        if ($redirectStatus >= 300 && $redirectStatus < 400) {
            // Never let the sandboxed frame follow a redirect off the signed
            // target: replace it with an analyzable error document (this also
            // drops the Location header).
            $response = $this->hardener->createMinimalHtmlResponse($redirectStatus);
        }

        if (!$this->isHtmlResponse($response)) {
            // Never expose active non-HTML content such as SVG under a preview
            // capability. A successful non-HTML response becomes an explicit
            // analysis error; existing error status codes are preserved.
            $status = $response->getStatusCode();
            $response = $this->hardener->createMinimalHtmlResponse($status >= 200 && $status < 300 ? 415 : $status);
        }

        $contentType = $response->getHeaderLine('Content-Type');
        if (str_starts_with(strtolower($contentType), 'application/xhtml+xml')) {
            // The runner below is inserted with HTML rules and contains raw,
            // XML-significant JavaScript (`&&`, `<`), so the rewritten body is
            // not well-formed XML and an XML parse would never execute it.
            // Re-label the analysis copy as text/html (any charset parameter is
            // preserved); browsers parse XHTML markup fine with the HTML parser.
            $response = $response->withHeader(
                'Content-Type',
                'text/html' . substr($contentType, strlen('application/xhtml+xml')),
            );
        }

        $nonce = StringUtility::base64urlEncode(random_bytes(18));
        $runnerFile = GeneralUtility::getFileAbsFileName(
            'EXT:mindfula11y/Resources/Public/JavaScript/service/structure/runner.js'
        );
        $runnerScript = $runnerFile !== '' && is_file($runnerFile) && is_readable($runnerFile)
            ? (string)file_get_contents($runnerFile)
            : '';
        if ($runnerScript === '') {
            // The bundled runner is missing (broken build/deploy). Without it
            // the framed document cannot report results. Discard the privileged
            // frontend body and return a non-scripted, non-cacheable error.
            return $this->hardener->createNonScriptedErrorResponse($ticket->backendOrigin);
        }
        // The framed document runs in an opaque origin (the iframe sandbox has
        // no `allow-same-origin`), so an external `type="module"` `src` is
        // fetched cross-origin and refused by CORS — the static asset carries no
        // `Access-Control-Allow-Origin`. Inline the self-contained runner bundle
        // instead: inline module scripts are never CORS-checked, and the
        // document CSP below authorizes this one by its nonce.
        $marker = sprintf(
            '<script id="mindfula11y-structure-analysis-runner" type="module" nonce="%s" data-request-id="%s" data-backend-origin="%s" data-status="%d">%s</script>',
            htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($ticket->requestId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($ticket->backendOrigin, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $response->getStatusCode(),
            // Defuse any literal `</script>` in the bundle so it cannot close the
            // inline element early (esbuild output currently contains none).
            str_ireplace('</script', '<\/script', $runnerScript),
        );
        $content = (string)$response->getBody();
        // An analysis response has its own CSP below. Remove meta policies and
        // automatic refreshes that could block the runner or navigate the frame.
        $content = preg_replace(
            '/<meta\b[^>]*http-equiv\s*=\s*(["\'])?(?:content-security-policy|refresh)\1[^>]*>/i',
            '',
            $content,
        ) ?? $content;
        // Insert via callback: a plain preg_replace() would parse the runner
        // bundle's bytes as replacement syntax, turning e.g. a literal `\0`
        // (present in the current build) into the matched `</body>` and
        // corrupting the inline module.
        $rewrittenContent = preg_replace_callback(
            '/<\/body\s*>/i',
            static fn(array $matches): string => $marker . $matches[0],
            $content,
            1,
            $count,
        );
        if (!is_string($rewrittenContent)) {
            // Fail closed: never ship the original frontend body without the
            // hardened CSP below (its own scripts would run in the framed
            // document). The minimal document still carries the runner so the
            // analysis reports fast instead of timing out.
            $rewrittenContent = '<!doctype html><html><body>' . $marker . '</body></html>';
        } elseif ($count === 0) {
            $rewrittenContent = $marker . $rewrittenContent;
        }

        $contentSecurityPolicy = "script-src 'nonce-{$nonce}' 'strict-dynamic'; object-src 'none'; frame-src 'none'; form-action 'none'; base-uri 'self'; frame-ancestors " . $ticket->backendOrigin;
        return $this->hardener->harden($response, $contentSecurityPolicy)
            ->withBody($this->streamFactory->createStream($rewrittenContent));
    }

}
