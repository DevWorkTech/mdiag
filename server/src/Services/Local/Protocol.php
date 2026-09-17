<?php

declare(strict_types=1);
namespace DevWorkTech\MDiag\Services\Local;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Разбор SOAP без DTD/внешних сущностей и сериализация ответов локального API. */
final class Protocol
{
    public const NS = 'https://79.174.70.97'; // Пространство имён из APK, не адрес запроса.

    /** @return array{method: ?string, params: array, auth: array} */
    public function parse(Request $request): array
    {
        $body = $request->getContent();
        if (!str_starts_with(ltrim($body), '<')) {
            foreach ($request->all() as $value) {
                if (!is_scalar($value) && $value !== null) { throw new AccessDenied('invalid_request'); }
            }
            return ['method' => null, 'params' => $request->all(), 'auth' => []];
        }
        if (strlen($body) > 1048576 || preg_match('/<!DOCTYPE|<!ENTITY/i', $body)) {
            throw new AccessDenied('invalid_request');
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // LIBXML_NONET запрещает сеть; NOENT/DTDLOAD намеренно не используются.
            if (!$doc->loadXML($body, LIBXML_NONET)) {
                throw new AccessDenied('invalid_request');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://schemas.xmlsoap.org/soap/envelope/');
        $methods = $xpath->query('/s:Envelope/s:Body/*');
        if ($methods->length !== 1) {
            throw new AccessDenied('invalid_request');
        }
        $method = $methods->item(0);
        $params = [];
        foreach ($method->childNodes as $child) {
            if (!$child instanceof DOMElement) { continue; }
            if (isset($params[$child->localName]) || $child->getElementsByTagName('*')->length > 0) {
                throw new AccessDenied('invalid_request');
            }
            $params[$child->localName] = $child->textContent;
        }
        $auth = [];
        foreach ($xpath->query('/s:Envelope/s:Header/*[local-name()="authenticate"]/*') as $child) {
            $auth[$child->localName] = $child->textContent;
        }
        return ['method' => $method->localName, 'params' => $params, 'auth' => $auth];
    }

    /** Отдельные SOAP DTO формируются из локальных данных, чужие снимки не выдаются. */
    public function reply(array $parsed, array $payload, int $status = 200): Response
    {
        if ($parsed['method'] === null) {
            return response()->json($payload, $status)->header('Cache-Control', 'no-store');
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $envelope = $doc->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soap:Envelope');
        $doc->appendChild($envelope);
        $body = $doc->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soap:Body');
        $envelope->appendChild($body);
        $method = $doc->createElementNS(self::NS, 'm:' . $parsed['method'] . 'Response');
        $body->appendChild($method);
        $result = $doc->createElement('return');
        $method->appendChild($result);
        $this->append($doc, $result, $payload);
        return response($doc->saveXML(), $status)
            ->header('Content-Type', 'text/xml; charset=UTF-8')->header('Cache-Control', 'no-store');
    }

    /** createTextNode экранирует текст администратора и названия модулей как XML. */
    private function append(DOMDocument $doc, DOMElement $parent, array $values): void
    {
        foreach ($values as $key => $value) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', (string) $key)) { continue; }
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $item) {
                    $node = $doc->createElement((string) $key);
                    $parent->appendChild($node);
                    is_array($item) ? $this->append($doc, $node, $item)
                        : $node->appendChild($doc->createTextNode((string) $item));
                }
                continue;
            }
            $node = $doc->createElement((string) $key);
            $parent->appendChild($node);
            if (is_array($value)) { $this->append($doc, $node, $value); }
            elseif ($value !== null) { $node->appendChild($doc->createTextNode(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value)); }
        }
    }
}
