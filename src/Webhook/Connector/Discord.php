<?php
namespace App\Webhook\Connector;

use App\Entity;
use GuzzleHttp\Exception\TransferException;
use Monolog\Logger;

/*
 * https://discordapp.com/developers/docs/resources/webhook#execute-webhook
 *
 * JSON/Form Params
 * content	string	the message contents (up to 2000 characters)	one of content, file, embeds
 * username	string	override the default username of the webhook	false
 * avatar_url	string	override the default avatar of the webhook	false
 * tts	bool	true if this is a TTS message	false
 * file	file contents	the contents of the file being sent	one of content, file, embeds
 * embeds	array of embed objects	embedded rich content	one of content, file, embeds
 *
 * Embed Structure
 * title	string	title of embed
 * type	string	type of embed (always "rich" for webhook embeds)
 * description	string	description of embed
 * url	string	url of embed
 * timestamp	ISO8601 timestamp	timestamp of embed content
 * color	integer	color code of the embed
 * footer	embed footer object	footer information
 * image	embed image object	image information
 * thumbnail	embed thumbnail object	thumbnail information
 * video	embed video object	video information
 * provider	embed provider object	provider information
 * author	embed author object	author information
 * fields	array of embed field objects	fields information
 *
 * Embed Footer Structure
 * text	string	footer text
 * icon_url	string	url of footer icon (only supports http(s) and attachments)
 * proxy_icon_url	string	a proxied url of footer icon
 *
 * Embed Thumbnail Structure
 * url	string	source url of thumbnail (only supports http(s) and attachments)
 * proxy_url	string	a proxied url of the thumbnail
 * height	integer	height of thumbnail
 * width	integer	width of thumbnail
 *
 * Embed Provider Structure
 * name	string	name of provider
 * url	string	url of provider
 *
 * Embed Footer Structure
 * text	string	footer text
 * icon_url	string	url of footer icon (only supports http(s) and attachments)
 * proxy_icon_url	string	a proxied url of footer icon
 *
 * Embed Field Structure
 * name	string	name of the field
 * value	string	value of the field
 * inline	bool	whether or not this field should display inline
 */


class Discord extends AbstractConnector
{
    /**
     * @param Entity\Station $station
     * @param Entity\Api\NowPlaying $np
     * @param array $config
     */
    public function dispatch(Entity\Station $station, Entity\Api\NowPlaying $np, array $config): void
    {
        $webhook_url = $this->_getValidUrl($config['webhook_url'] ?? '');

        if (empty($webhook_url)) {
            $this->logger->error('Webhook '.get_called_class().' is missing necessary configuration. Skipping...');
            return;
        }

        $raw_vars = [
            'content' => $config['content'] ?? '',
            'title' => $config['title'] ?? '',
            'description' => $config['description'] ?? '',
            'url' => $config['url'] ?? '',
            'author' => $config['author'] ?? '',
            'thumbnail' => $config['thumbnail'] ?? '',
            'footer' => $config['footer'] ?? '',
        ];

        $vars = $this->_replaceVariables($raw_vars, $np);

        // Compose webhook
        $embed = [
            'title' => $vars['title'] ?? '',
            'description' => $vars['description'] ?? '',
            'url' => $this->_getValidUrl($vars['url']) ?? '',
            'color' => 2201331, // #2196f3
        ];
        $embed = array_filter($embed);

        if (!empty($vars['author'])) {
            $embed['author'] = [
                'name' => $vars['author'],
            ];
        }
        if (!empty($vars['thumbnail']) && $this->_getValidUrl($vars['thumbnail'])) {
            $embed['thumbnail'] = [
                'url' => $this->_getValidUrl($vars['thumbnail']),
            ];
        }
        if (!empty($vars['footer'])) {
            $embed['footer'] = [
                'text' => $vars['footer'],
            ];
        }

        $webhook_body = [];
        $webhook_body['content'] = $vars['content'] ?? '';

        // Don't include an embed if all relevant fields are empty.
        if (count($embed) > 1) {
            $webhook_body['embeds'] = [$embed];
        }

        // Dispatch webhook
        $this->logger->debug('Dispatching Discord webhook...');

        $client = new \GuzzleHttp\Client([
            'http_errors' => false,
            'timeout' => 2.0,
        ]);

        try {
            $response = $client->request('POST', $webhook_url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $webhook_body,
            ]);

            $this->logger->addRecord(
                ($response->getStatusCode() !== 204 ? Logger::ERROR : Logger::DEBUG),
                sprintf('Discord returned code %d', $response->getStatusCode()),
                ['message_sent' => $webhook_body, 'response_body' => $response->getBody()->getContents()]
            );
        } catch(TransferException $e) {
            $this->logger->error(sprintf('Error from Discord (%d): %s', $e->getCode(), $e->getMessage()));
        }
    }
}
