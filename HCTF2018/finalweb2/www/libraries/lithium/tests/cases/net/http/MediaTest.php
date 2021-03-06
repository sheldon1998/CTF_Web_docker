<?php
/**
 * li₃: the most RAD framework for PHP (http://li3.me)
 *
 * Copyright 2016, Union of RAD. All rights reserved. This source
 * code is distributed under the terms of the BSD 3-Clause License.
 * The full license text can be found in the LICENSE.txt file.
 */

namespace lithium\tests\cases\net\http;

use lithium\core\Environment;
use lithium\net\http\Media;
use lithium\action\Request;
use lithium\action\Response;
use lithium\core\Libraries;
use lithium\data\entity\Record;
use lithium\data\collection\RecordSet;

class MediaTest extends \lithium\test\Unit {

	/**
	 * Reset the `Media` class to its default state.
	 */
	public function tearDown() {
		Media::reset();
	}

	/**
	 * Tests setting, getting and removing custom media types.
	 */
	public function testMediaTypes() {
		// Get a list of all available media types:
		$types = Media::types(); // returns ['html', 'json', 'rss', ...];

		$expected = [
			'html', 'htm', 'form', 'json', 'rss', 'atom', 'css', 'js', 'text', 'txt', 'xml'
		];
		$this->assertEqual($expected, $types);
		$this->assertEqual($expected, Media::formats());

		$result = Media::type('json');
		$expected = ['application/json'];
		$this->assertEqual($expected, $result['content']);

		$expected = [
			'cast' => true, 'encode' => 'json_encode', 'decode' => $result['options']['decode']
		];
		$this->assertEqual($expected, $result['options']);

		// Add a custom media type with a custom view class:
		Media::type('my', 'text/x-my', [
			'view' => 'my\custom\View',
			'paths' => ['layout' => false]
		]);

		$result = Media::types();
		$this->assertTrue(in_array('my', $result));

		$result = Media::type('my');
		$expected = ['text/x-my'];
		$this->assertEqual($expected, $result['content']);

		$expected = [
			'view' => 'my\custom\View',
			'paths' => [
				'template' => '{:library}/views/{:controller}/{:template}.{:type}.php',
				'layout' => false,
				'element' => '{:library}/views/elements/{:template}.{:type}.php'
			],
			'encode' => null, 'decode' => null, 'cast' => true, 'conditions' => []
		];
		$this->assertEqual($expected, $result['options']);

		// Remove a custom media type:
		Media::type('my', false);
		$result = Media::types();
		$this->assertFalse(in_array('my', $result));
	}

	/**
	 * Tests that `Media` will return the correct type name of recognized, registered content types.
	 */
	public function testContentTypeDetection() {
		$this->assertNull(Media::type('application/foo'));
		$this->assertEqual('js', Media::type('application/javascript'));
		$this->assertEqual('html', Media::type('*/*'));
		$this->assertEqual('json', Media::type('application/json'));
		$this->assertEqual('json', Media::type('application/json; charset=UTF-8'));

		$result = Media::type('json');
		$expected = ['content' => ['application/json'], 'options' => [
			'cast' => true, 'encode' => 'json_encode', 'decode' => $result['options']['decode']
		]];
		$this->assertEqual($expected, $result);
	}

	public function testAssetTypeHandling() {
		$result = Media::assets();
		$expected = ['js', 'css', 'image', 'generic'];
		$this->assertEqual($expected, array_keys($result));

		$result = Media::assets('css');
		$expected = '.css';
		$this->assertEqual($expected, $result['suffix']);
		$this->assertTrue(isset($result['paths']['{:base}/{:library}/css/{:path}']));

		$result = Media::assets('my');
		$this->assertNull($result);

		$result = Media::assets('my', ['suffix' => '.my', 'paths' => [
			'{:base}/my/{:path}' => ['base', 'path']
		]]);
		$this->assertNull($result);

		$result = Media::assets('my');
		$expected = '.my';
		$this->assertEqual($expected, $result['suffix']);
		$this->assertTrue(isset($result['paths']['{:base}/my/{:path}']));

		$this->assertNull($result['filter']);
		Media::assets('my', ['filter' => ['/my/' => '/your/']]);

		$result = Media::assets('my');
		$expected = ['/my/' => '/your/'];
		$this->assertEqual($expected, $result['filter']);

		$expected = '.my';
		$this->assertEqual($expected, $result['suffix']);

		Media::assets('my', false);
		$result = Media::assets('my');
		$this->assertNull($result);

		$this->assertEqual('/foo.exe', Media::asset('foo.exe', 'bar'));
	}

	public function testAssetAbsoluteRelativePaths() {
		$result = Media::asset('scheme://host/subpath/file', 'js');
		$expected = 'scheme://host/subpath/file';
		$this->assertEqual($expected, $result);

		$result = Media::asset('//host/subpath/file', 'js', ['base' => '/base']);
		$expected = '//host/subpath/file';
		$this->assertEqual($expected, $result);

		$result = Media::asset('subpath/file', 'js');
		$expected = '/js/subpath/file.js';
		$this->assertEqual($expected, $result);
	}

	public function testCustomAssetUrls() {
		$env = Environment::get();

		$path = Libraries::get(true, 'path');
		Libraries::add('cdn_js_test', [
			'path' => $path,
			'assets' => [
				'js' => 'http://static.cdn.com'
			],
			'bootstrap' => false
		]);

		Libraries::add('cdn_env_test', [
			'path' => $path,
			'assets' => [
				'js' => 'wrong',
				$env => ['js' => 'http://static.cdn.com/myapp']
			],
			'bootstrap' => false
		]);
		$library = basename($path);

		$result = Media::asset('foo', 'js', ['library' => 'cdn_js_test']);
		$this->assertEqual("http://static.cdn.com/{$library}/js/foo.js", $result);

		$result = Media::asset('foo', 'css', ['library' => 'cdn_js_test']);
		$this->assertEqual("/{$library}/css/foo.css", $result);

		$result = Media::asset('foo', 'js', ['library' => 'cdn_env_test']);
		$this->assertEqual("http://static.cdn.com/myapp/{$library}/js/foo.js", $result);

		Libraries::remove('cdn_env_test');
		Libraries::remove('cdn_js_test');
	}

	public function testAssetPathGeneration() {
		$resources = Libraries::get(true, 'resources');
		$this->skipIf(!is_writable($resources), "Cannot write test app to resources directory.");
		$paths = ["{$resources}/media_test/webroot/css", "{$resources}/media_test/webroot/js"];

		foreach ($paths as $path) {
			if (!is_dir($path)) {
				mkdir($path, 0777, true);
			}
		}
		touch("{$paths[0]}/debug.css");

		Libraries::add('media_test', ['path' => "{$resources}/media_test"]);

		$result = Media::asset('debug', 'css', ['check' => true, 'library' => 'media_test']);
		$this->assertEqual('/media_test/css/debug.css', $result);

		$result = Media::asset('debug', 'css', [
			'timestamp' => true, 'library' => 'media_test'
		]);
		$this->assertPattern('%^/media_test/css/debug\.css\?\d+$%', $result);

		$result = Media::asset('debug.css?type=test', 'css', [
			'check' => true, 'base' => 'foo', 'library' => 'media_test'
		]);
		$this->assertEqual('foo/media_test/css/debug.css?type=test', $result);

		$result = Media::asset('debug.css?type=test', 'css', [
			'check' => true, 'base' => 'foo', 'timestamp' => true, 'library' => 'media_test'
		]);
		$this->assertPattern('%^foo/media_test/css/debug\.css\?type=test&\d+$%', $result);

		$file = Media::path('css/debug.css', 'bar', ['library' => 'media_test']);
		$this->assertFileExists($file);

		$result = Media::asset('this.file.should.not.exist', 'css', ['check' => true]);
		$this->assertFalse($result);

		unlink("{$paths[0]}/debug.css");

		foreach (array_merge($paths, [dirname($paths[0])]) as $path) {
			rmdir($path);
		}
	}

	public function testCustomAssetPathGeneration() {
		Media::assets('my', ['suffix' => '.my', 'paths' => [
			'{:base}/my/{:path}' => ['base', 'path']
		]]);

		$result = Media::asset('subpath/file', 'my');
		$expected = '/my/subpath/file.my';
		$this->assertEqual($expected, $result);

		Media::assets('my', ['filter' => ['/my/' => '/your/']]);

		$result = Media::asset('subpath/file', 'my');
		$expected = '/your/subpath/file.my';
		$this->assertEqual($expected, $result);

		$result = Media::asset('subpath/file', 'my', ['base' => '/app/path']);
		$expected = '/app/path/your/subpath/file.my';
		$this->assertEqual($expected, $result);

		$result = Media::asset('subpath/file', 'my', ['base' => '/app/path/']);
		$expected = '/app/path//your/subpath/file.my';
		$this->assertEqual($expected, $result);
	}

	public function testMultiLibraryAssetPaths() {
		$result = Media::asset('path/file', 'js', ['library' => true, 'base' => '/app/base']);
		$expected = '/app/base/js/path/file.js';
		$this->assertEqual($expected, $result);

		Libraries::add('li3_foo_blog', [
			'path' => Libraries::get(true, 'path') . '/libraries/plugins/blog',
			'bootstrap' => false,
			'route' => false
		]);

		$result = Media::asset('path/file', 'js', [
			'library' => 'li3_foo_blog', 'base' => '/app/base'
		]);
		$expected = '/app/base/blog/js/path/file.js';
		$this->assertEqual($expected, $result);

		Libraries::remove('li3_foo_blog');
	}

	public function testManualAssetPaths() {
		$result = Media::asset('/path/file', 'js', ['base' => '/base']);
		$expected = '/base/path/file.js';
		$this->assertEqual($expected, $result);

		$resources = Libraries::get(true, 'resources');
		$cssPath = "{$resources}/media_test/webroot/css";
		$this->skipIf(!is_writable($resources), "Cannot write test app to resources directory.");

		if (!is_dir($cssPath)) {
			mkdir($cssPath, 0777, true);
		}

		Libraries::add('media_test', ['path' => "{$resources}/media_test"]);

		$result = Media::asset('/foo/bar', 'js', ['base' => '/base', 'check' => true]);
		$this->assertFalse($result);

		file_put_contents("{$cssPath}/debug.css", "html, body { background-color: black; }");
		$result = Media::asset('/css/debug', 'css', [
			'library' => 'media_test', 'base' => '/base', 'check' => true
		]);
		$expected = '/base/css/debug.css';
		$this->assertEqual($expected, $result);

		$result = Media::asset('/css/debug.css', 'css', [
			'library' => 'media_test', 'base' => '/base', 'check' => true
		]);
		$expected = '/base/css/debug.css';
		$this->assertEqual($expected, $result);

		$result = Media::asset('/css/debug.css?foo', 'css', [
			'library' => 'media_test', 'base' => '/base', 'check' => true
		]);
		$expected = '/base/css/debug.css?foo';
		$this->assertEqual($expected, $result);

		Libraries::remove('media_test');
		unlink("{$cssPath}/debug.css");

		foreach ([$cssPath, dirname($cssPath)] as $path) {
			rmdir($path);
		}
	}

	public function testRender() {
		$response = new Response();
		$response->type('json');
		$data = ['something'];
		Media::render($response, $data);

		$result = $response->headers();
		$this->assertEqual(['Content-Type: application/json; charset=UTF-8'], $result);

		$result = $response->body();
		$this->assertEqual($data, $result);
	}

	/**
	 * Tests that a decode handler is not called when the Media type has none configured.
	 */
	public function testNoDecode() {
		Media::type('my', 'text/x-my', ['decode' => false]);

		$result = Media::decode('my', 'Hello World');
		$this->assertEqual(null, $result);
	}

	/**
	 * Tests that types with decode handlers can properly decode content.
	 */
	public function testDecode() {
		$data = ['movies' => [
			['name' => 'Shaun of the Dead', 'year' => 2004],
			['name' => 'V for Vendetta', 'year' => 2005]
		]];
		$jsonEncoded = '{"movies":[{"name":"Shaun of the Dead","year":2004},';
		$jsonEncoded .= '{"name":"V for Vendetta","year":2005}]}';

		$result = Media::decode('json', $jsonEncoded);
		$this->assertEqual($data, $result);

		$formEncoded = 'movies%5B0%5D%5Bname%5D=Shaun+of+the+Dead&movies%5B0%5D%5Byear%5D=2004';
		$formEncoded .= '&movies%5B1%5D%5Bname%5D=V+for+Vendetta&movies%5B1%5D%5Byear%5D=2005';

		$result = Media::decode('form', $formEncoded);
		$this->assertEqual($data, $result);
	}

	public function testCustomEncodeHandler() {
		$response = new Response();

		Media::type('csv', 'application/csv', [
			'encode' => function($data) {
				ob_start();
				$out = fopen('php://output', 'w');
				foreach ($data as $record) {
					fputcsv($out, $record);
				}
				fclose($out);
				return ob_get_clean();
			}
		]);

		$data = [
			['John', 'Doe', '123 Main St.', 'Anytown, CA', '91724'],
			['Jane', 'Doe', '124 Main St.', 'Anytown, CA', '91724']
		];
		$response->type('csv');
		Media::render($response, $data);
		$result = $response->body;
		$expected = 'John,Doe,"123 Main St.","Anytown, CA",91724' . "\n";
		$expected .= 'Jane,Doe,"124 Main St.","Anytown, CA",91724' . "\n";
		$this->assertEqual([$expected], $result);

		$result = $response->headers['Content-Type'];
		$this->assertEqual('application/csv; charset=UTF-8', $result);
	}

	public function testEmptyEncode() {
		$handler = Media::type('empty', 'empty/encode');
		$this->assertNull(Media::encode($handler, []));

		$handler = Media::type('empty', 'empty/encode', [
			'encode' => null
		]);
		$this->assertNull(Media::encode($handler, []));

		$handler = Media::type('empty', 'empty/encode', [
			'encode' => false
		]);
		$this->assertNull(Media::encode($handler, []));

		$handler = Media::type('empty', 'empty/encode', [
			'encode' => ""
		]);
		$this->assertNull(Media::encode($handler, []));
	}

	/**
	 * Tests that rendering plain text correctly returns the render data as-is.
	 */
	public function testPlainTextOutput() {
		$response = new Response();
		$response->type('text');
		Media::render($response, "Hello, world!");

		$result = $response->body;
		$this->assertEqual(["Hello, world!"], $result);
	}

	/**
	 * Tests that an exception is thrown for cases where an attempt is made to render content for
	 * a type which is not registered.
	 */
	public function testUndhandledContent() {
		$response = new Response();
		$response->type('bad');

		$this->assertException("Unhandled media type `bad`.", function() use ($response) {
			Media::render($response, ['foo' => 'bar']);
		});

		$result = $response->body();
		$this->assertIdentical('', $result);
	}

	/**
	 * Tests that attempts to render a media type with no handler registered produces an
	 * 'unhandled media type' exception, even if the type itself is a registered content type.
	 */
	public function testUnregisteredContentHandler() {
		$response = new Response();
		$response->type('xml');

		$this->assertException("Unhandled media type `xml`.", function() use ($response) {
			Media::render($response, ['foo' => 'bar']);
		});

		$result = $response->body;
		$this->assertNull($result);
	}

	/**
	 * Tests handling content type manually using parameters to `Media::render()`, for content types
	 * that are registered but have no default handler.
	 */
	public function testManualContentHandling() {
		Media::type('custom', 'text/x-custom');
		$response = new Response();
		$response->type('custom');

		Media::render($response, 'Hello, world!', [
			'layout' => false,
			'template' => false,
			'encode' => function($data) { return "Message: {$data}"; }
		]);

		$result = $response->body;
		$expected = ["Message: Hello, world!"];
		$this->assertEqual($expected, $result);

		$this->assertException("/Template not found/", function() use ($response) {
			Media::render($response, 'Hello, world!');
		});
	}

	/**
	 * Tests that parameters from the `Request` object passed into `render()` via
	 * `$options['request']` are properly merged into the `$options` array passed to render
	 * handlers.
	 */
	public function testRequestOptionMerging() {
		Media::type('custom', 'text/x-custom');
		$request = new Request();
		$request->params['foo'] = 'bar';

		$response = new Response();
		$response->type('custom');

		Media::render($response, null, compact('request') + [
			'layout' => false,
			'template' => false,
			'encode' => function($data, $handler) { return $handler['request']->foo; }
		]);
		$this->assertEqual(['bar'], $response->body);
	}

	public function testMediaEncoding() {
		$data = ['hello', 'goodbye', 'foo' => ['bar', 'baz' => 'dib']];
		$expected = json_encode($data);
		$result = Media::encode('json', $data);
		$this->assertEqual($expected, $result);

		$this->assertEqual($result, Media::to('json', $data));
		$this->assertNull(Media::encode('badness', $data));

		$result = Media::decode('json', $expected);
		$this->assertEqual($data, $result);
	}

	public function testRenderWithOptionsMerging() {
		$base = Libraries::get(true, 'resources') . '/tmp';
		$this->skipIf(!is_writable($base), "Path `{$base}` is not writable.");

		$request = new Request();
		$request->params['controller'] = 'pages';

		$response = new Response();
		$response->type('html');

		$this->assertException("/Template not found/", function() use ($response) {
			Media::render($response, null, compact('request'));
		});

		$this->_cleanUp();
	}

	public function testCustomWebroot() {
		Libraries::add('defaultStyleApp', [
			'path' => Libraries::get(true, 'path'),
			'bootstrap' => false]
		);
		$this->assertEqual(
			realpath(Libraries::get(true, 'path') . '/webroot'),
			realpath(Media::webroot('defaultStyleApp'))
		);

		Libraries::add('customWebRootApp', [
			'path' => Libraries::get(true, 'path'),
			'webroot' => Libraries::get(true, 'path'),
			'bootstrap' => false
		]);

		$this->assertEqual(Libraries::get(true, 'path'), Media::webroot('customWebRootApp'));

		Libraries::remove('defaultStyleApp');
		Libraries::remove('customWebRootApp');
		$this->assertNull(Media::webroot('defaultStyleApp'));
	}

	/**
	 * Tests that the `Media` class' configuration can be reset to its default state.
	 */
	public function testStateReset() {
		$this->assertFalse(in_array('foo', Media::types()));

		Media::type('foo', 'text/x-foo');
		$this->assertTrue(in_array('foo', Media::types()));

		Media::reset();
		$this->assertFalse(in_array('foo', Media::types()));
	}

	public function testEncodeRecordSet() {
		$data = new RecordSet(['data' => [
			1 => new Record(['data' => ['id' => 1, 'foo' => 'bar']]),
			2 => new Record(['data' => ['id' => 2, 'foo' => 'baz']]),
			3 => new Record(['data' => ['id' => 3, 'baz' => 'dib']])
		]]);
		$json = '{"1":{"id":1,"foo":"bar"},"2":{"id":2,"foo":"baz"},"3":{"id":3,"baz":"dib"}}';
		$this->assertEqual($json, Media::encode(['encode' => 'json_encode'], $data));
	}

	public function testEncodeNotCallable() {
		$data = ['foo' => 'bar'];
		$result = Media::encode(['encode' => false], $data);
		$this->assertNull($result);
	}

	/**
	 * Tests that calling `Media::type()` to retrieve the details of a type that is aliased to
	 * another type, automatically resolves to the settings of the type being pointed at.
	 */
	public function testTypeAliasResolution() {
		$resolved = Media::type('text');
		$this->assertEqual(['text/plain'], $resolved['content']);
		unset($resolved['options']['encode']);

		$result = Media::type('txt');
		unset($result['options']['encode']);
		$this->assertEqual($resolved, $result);
	}

	public function testQueryUndefinedAssetTypes() {
		$base = Media::path('index.php', 'generic');
		$result = Media::path('index.php', 'foo');
		$this->assertEqual($result, $base);

		$base = Media::asset('/bar', 'generic');
		$result = Media::asset('/bar', 'foo');
		$this->assertEqual($result, $base);
	}

	public function testGetLibraryWebroot() {
		$this->assertNull(Media::webroot('foobar'));

		Libraries::add('foobar', ['path' => __DIR__, 'webroot' => __DIR__]);
		$this->assertEqual(__DIR__, Media::webroot('foobar'));
		Libraries::remove('foobar');

		$resources = Libraries::get(true, 'resources');
		$webroot = "{$resources}/media_test/webroot";
		$this->skipIf(!is_writable($resources), "Cannot write test app to resources directory.");

		if (!is_dir($webroot)) {
			mkdir($webroot, 0777, true);
		}

		Libraries::add('media_test', ['path' => "{$resources}/media_test"]);
		$this->assertFileExists(Media::webroot('media_test'));
		Libraries::remove('media_test');
		rmdir($webroot);
	}

	/**
	 * Tests that the `Response` object can be directly modified from a templating class or encode
	 * function.
	 */
	public function testResponseModification() {
		Media::type('my', 'text/x-my', ['view' => 'lithium\tests\mocks\net\http\Template']);
		$response = new Response();

		Media::render($response, null, ['type' => 'my']);
		$this->assertEqual('Value', $response->headers('Custom'));
	}

	/**
	 * Tests that `Media::asset()` will not prepend path strings with the base application path if
	 * it has already been prepended.
	 */
	public function testDuplicateBasePathCheck() {
		$result = Media::asset('/foo/bar/image.jpg', 'image', ['base' => '/bar']);
		$this->assertEqual('/bar/foo/bar/image.jpg', $result);

		$result = Media::asset('/foo/bar/image.jpg', 'image', ['base' => '/foo/bar']);
		$this->assertEqual('/foo/bar/image.jpg', $result);

		$result = Media::asset('foo/bar/image.jpg', 'image', ['base' => 'foo']);
		$this->assertEqual('foo/img/foo/bar/image.jpg', $result);

		$result = Media::asset('/foo/bar/image.jpg', 'image', ['base' => '']);
		$this->assertEqual('/foo/bar/image.jpg', $result);
	}

	public function testContentNegotiationSimple() {
		$request = new Request(['env' => [
			'HTTP_ACCEPT' => 'text/html,text/plain;q=0.5'
		]]);
		$this->assertEqual('html', Media::negotiate($request));

		$request = new Request(['env' => [
			'HTTP_ACCEPT' => 'application/json'
		]]);
		$this->assertEqual('json', Media::negotiate($request));
	}

	public function testContentNegotiationByType() {
		$this->assertEqual('html', Media::type('text/html'));

		Media::type('jsonp', 'text/html', [
			'conditions' => ['type' => true]
		]);
		$this->assertEqual(['jsonp', 'html'], Media::type('text/html'));

		$config = ['env' => ['HTTP_ACCEPT' => 'text/html,text/plain;q=0.5']];
		$request = new Request($config);
		$request->params = ['type' => 'jsonp'];
		$this->assertEqual('jsonp', Media::negotiate($request));

		$request = new Request($config);
		$this->assertEqual('html', Media::negotiate($request));
	}

	public function testContentNegotiationByUserAgent() {
		Media::type('iphone', 'application/xhtml+xml', [
			'conditions' => ['mobile' => true]
		]);
		$request = new Request(['env' => [
			'HTTP_USER_AGENT' => 'Safari',
			'HTTP_ACCEPT' => 'application/xhtml+xml,text/html'
		]]);
		$this->assertEqual('html', Media::negotiate($request));

		$request = new Request(['env' => [
			'HTTP_USER_AGENT' => 'iPhone',
			'HTTP_ACCEPT' => 'application/xhtml+xml,text/html'
		]]);
		$this->assertEqual('iphone', Media::negotiate($request));
	}

	/**
	 * Tests that empty asset paths correctly return the base path for the asset type, and don't
	 * generate notices or errors.
	 */
	public function testEmptyAssetPaths() {
		$this->assertEqual('/img/', Media::asset('', 'image'));
		$this->assertEqual('/css/.css', Media::asset('', 'css'));
		$this->assertEqual('/js/.js', Media::asset('', 'js'));
		$this->assertEqual('/', Media::asset('', 'generic'));
	}

	public function testLocation() {
		$webroot = Libraries::get(true, 'resources') . '/tmp/tests/webroot';
		mkdir($webroot, 0777, true);

		$webroot = realpath($webroot);
		$this->assertNotEmpty($webroot);
		Media::attach('tests', [
			'absolute' => true,
			'host' => 'www.hostname.com',
			'scheme' => 'http://',
			'prefix' => '/web/assets/tests',
			'path' => $webroot
		]);
		Media::attach('app', [
			'absolute' => false,
			'prefix' => '/web/assets/app',
			'path' => $webroot
		]);

		$expected = [
			'absolute' => false,
			'host' => 'localhost',
			'scheme' => 'http://',
			'base' => null,
			'prefix' => 'web/assets/app',
			'path' => $webroot,
			'timestamp' => false,
			'filter' => null,
			'suffix' => null,
			'check' => false
		];
		$result = Media::attached('app');
		$this->assertEqual($expected, $result);

		$expected = [
			'absolute' => true,
			'host' => 'www.hostname.com',
			'scheme' => 'http://',
			'base' => null,
			'prefix' => 'web/assets/tests',
			'path' => $webroot,
			'timestamp' => false,
			'filter' => null,
			'suffix' => null,
			'check' => false
		];
		$result = Media::attached('tests');
		$this->assertEqual($expected, $result);
		$this->_cleanUp();
	}

	public function testAssetWithAbsoluteLocation() {
		Media::attach('appcdn', [
			'absolute' => true,
			'scheme' => 'http://',
			'host' => 'my.cdn.com',
			'prefix' => '/assets',
			'path' => null
		]);

		$result = Media::asset('style','css');
		$expected = '/css/style.css';
		$this->assertEqual($expected, $result);

		$result = Media::asset('style','css', ['scope' => 'appcdn']);
		$expected = 'http://my.cdn.com/assets/css/style.css';
		$this->assertEqual($expected, $result);

		Media::scope('appcdn');

		$result = Media::asset('style', 'css', ['scope' => false]);
		$expected = '/css/style.css';
		$this->assertEqual($expected, $result);

		$result = Media::asset('style', 'css');
		$expected = 'http://my.cdn.com/assets/css/style.css';
		$this->assertEqual($expected, $result);
	}

	/**
	 * Create environment prefix location using `lihtium\net\http\Media::location`
	 * Check if `lihtium\net\http\Media::asset` return the correct URL
	 * for the production environement
	 */
	public function testEnvironmentAsset1() {
		Media::attach('appcdn', [
			'production' => [
				'absolute' => true,
				'path' => null,
				'scheme' => 'http://',
				'host' => 'my.cdnapp.com',
				'prefix' => '/assets',
			],
			'test' => [
				'absolute' => true,
				'path' => null,
				'scheme' => 'http://',
				'host' => 'my.cdntest.com',
				'prefix' => '/assets',
			]
		]);

		$env = Environment::get();

		Environment::set('production');
		$result = Media::asset('style', 'css', ['scope' => 'appcdn']);
		$expected = 'http://my.cdnapp.com/assets/css/style.css';
	}

	/**
	 * Create environment prefix location using `lihtium\net\http\Media::location`
	 * Check if `lihtium\net\http\Media::asset` return the correct URL
	 * for the test environement
	 */
	public function testEnvironmentAsset2() {
		Media::attach('appcdn', [
			'production' => [
				'absolute' => true,
				'path' => null,
				'scheme' => 'http://',
				'host' => 'my.cdnapp.com',
				'prefix' => 'assets',
			],
			'test' => [
				'absolute' => true,
				'path' => null,
				'scheme' => 'http://',
				'host' => 'my.cdntest.com',
				'prefix' => 'assets',
			]
		]);

		$env = Environment::get();
		Environment::set('test');
		$result = Media::asset('style', 'css', ['scope' => 'appcdn']);
		$expected = 'http://my.cdntest.com/assets/css/style.css';
		$this->assertEqual($expected, $result);
		Environment::is($env);
	}

	public function testAssetPathGenerationWithLocation() {
		$resources = Libraries::get(true, 'resources') . '/tmp/tests';
		$this->skipIf(!is_writable($resources), "Cannot write test app to resources directory.");
		$paths = ["{$resources}/media_test/css", "{$resources}/media_test/js"];

		foreach ($paths as $path) {
			if (!is_dir($path)) {
				mkdir($path, 0777, true);
			}
		}
		touch("{$paths[0]}/debug.css");

		Media::attach('media_test', [
			'prefix' => '',
			'path' => "{$resources}/media_test"]
		);

		$result = Media::asset('debug', 'css', ['check' => true, 'scope' => 'media_test']);

		$this->assertEqual('/css/debug.css', $result);

		Media::attach('media_test', [
			'prefix' => 'media_test',
			'path' => "{$resources}/media_test"]
		);

		$result = Media::asset('debug', 'css', ['check' => true, 'scope' => 'media_test']);
		$this->assertEqual('/media_test/css/debug.css', $result);

		$result = Media::asset('debug', 'css', [
			'timestamp' => true, 'scope' => 'media_test'
		]);
		$this->assertPattern('%^/media_test/css/debug\.css\?\d+$%', $result);

		$result = Media::asset('/css/debug.css?type=test', 'css', [
			'check' => true, 'base' => 'foo', 'scope' => 'media_test'
		]);

		$this->assertEqual('foo/media_test/css/debug.css?type=test', $result);

		$result = Media::asset('/css/debug.css?type=test', 'css', [
			'check' => true, 'base' => 'http://www.hostname.com/foo', 'scope' => 'media_test'
		]);

		$expected = 'http://www.hostname.com/foo/media_test/css/debug.css?type=test';
		$this->assertEqual($expected, $result);

		$result = Media::asset('/css/debug.css?type=test', 'css', [
			'check' => true, 'base' => 'foo', 'timestamp' => true, 'scope' => 'media_test'
		]);
		$this->assertPattern('%^foo/media_test/css/debug\.css\?type=test&\d+$%', $result);

		$result = Media::asset('this.file.should.not.exist.css', 'css', ['check' => true]);
		$this->assertFalse($result);

		Media::attach('media_test', [
			'prefix' => 'media_test',
			'path' => "{$resources}/media_test"]
		);

		$result = Media::asset('debug', 'css', ['check' => true, 'scope' => 'media_test']);
		$this->assertEqual('/media_test/css/debug.css', $result);

		$result = Media::asset('debug', 'css', [
			'timestamp' => true, 'scope' => 'media_test'
		]);
		$this->assertPattern('%^/media_test/css/debug\.css\?\d+$%', $result);

		$result = Media::asset('/css/debug.css?type=test', 'css', [
			'check' => true, 'base' => 'foo', 'scope' => 'media_test'
		]);

		$this->assertEqual('foo/media_test/css/debug.css?type=test', $result);

		$result = Media::asset('/css/debug.css?type=test', 'css', [
			'check' => true, 'base' => 'foo', 'timestamp' => true, 'scope' => 'media_test'
		]);
		$this->assertPattern('%^foo/media_test/css/debug\.css\?type=test&\d+$%', $result);

		$result = Media::asset('this.file.should.not.exist.css', 'css', ['check' => true]);
		$this->assertFalse($result);

		unlink("{$paths[0]}/debug.css");

		foreach (array_merge($paths, [dirname($paths[0])]) as $path) {
			rmdir($path);
		}
	}

	public function testEmptyHostAndSchemeOptionLocation() {
		Media::attach('app', ['absolute' => true]);

		Media::scope('app');
		$result = Media::asset('/js/path/file', 'js', ['base' => '/app/base']);
		$expected = 'http://localhost/app/base/js/path/file.js';
		$this->assertEqual($expected, $result);
	}

	public function testDeleteLocation() {
		$result = Media::asset('/js/path/file', 'js', ['base' => '/app/base']);
		$expected = '/app/base/js/path/file.js';
		$this->assertEqual($expected, $result);

		Media::attach('foo_blog', [
			'prefix' => 'assets/plugin/blog'
		]);

		$result = Media::asset('/js/path/file', 'js', [
			'scope' => 'foo_blog', 'base' => '/app/base'
		]);
		$expected = '/app/base/assets/plugin/blog/js/path/file.js';
		$this->assertEqual($expected, $result);

		Media::attach('foo_blog', false);
		$this->assertEqual([], Media::attached('foo_blog'));
	}

	public function testListAttached() {
		Media::attach('media1', ['prefix' => 'media1', 'absolute' => true]);
		Media::attach('media2', ['prefix' => 'media2', 'check' => true]);
		Media::attach('media3', ['prefix' => 'media3']);

		$expected = [
			'media1' => [
				'prefix' => 'media1',
				'absolute' => true,
				'host' => 'localhost',
				'scheme' => 'http://',
				'base' => null,
				'path' => null,
				'timestamp' => false,
				'filter' => null,
				'suffix' => null,
				'check' => false
			],
			'media2' => [
				'prefix' => 'media2',
				'absolute' => false,
				'host' => 'localhost',
				'scheme' => 'http://',
				'base' => null,
				'path' => null,
				'timestamp' => false,
				'filter' => null,
				'suffix' => null,
				'check' => true
			],
			'media3' => [
				'prefix' => 'media3',
				'absolute' => false,
				'host' => 'localhost',
				'scheme' => 'http://',
				'base' => null,
				'path' => null,
				'timestamp' => false,
				'filter' => null,
				'suffix' => null,
				'check' => false
			]
		];

		$this->assertEqual($expected, Media::attached());
	}

	public function testMultipleHostsAndSchemeSelectSameIndex() {
		Media::attach('cdn', [
			'absolute' => true,
			'host' => ['cdn.com', 'cdn.org'],
			'scheme' => ['http://', 'https://'],
		]);

		$result = Media::asset('style.css', 'css', ['scope' => 'cdn']);
		$expected = '%https://cdn.org/css/style.css|http://cdn.com/css/style.css%';

		$this->assertPattern($expected, $result);
	}

	public function testMultipleHostsAndSingleSchemePicksOnlyScheme() {
		Media::attach('cdn', [
			'absolute' => true,
			'host' => ['cdn.com', 'cdn.org'],
			'scheme' => 'http://',
		]);

		$result = Media::asset('style.css', 'css', ['scope' => 'cdn']);
		$expected = '%http://cdn.org/css/style.css|http://cdn.com/css/style.css%';

		$this->assertPattern($expected, $result);
	}

	public function testMultipleHostsPickSameHostForIdenticalAsset() {
		Media::attach('cdn', [
			'absolute' => true,
			'host' => ['cdn.com', 'cdn.org'],
			'scheme' => 'http://',
		]);

		$first = Media::asset('style.css', 'css', ['scope' => 'cdn']);
		$second = Media::asset('style.css', 'css', ['scope' => 'cdn']);
		$third = Media::asset('style.css', 'css', ['scope' => 'cdn']);

		$this->assertIdentical($first, $second);
		$this->assertIdentical($third, $second);
	}

	public function testScopeBase() {
		$result = Media::asset('style.css', 'css');
		$this->assertEqual('/css/style.css', $result);

		Media::attach(false, ['base' => 'lithium/app/webroot']);
		$result = Media::asset('style.css', 'css');
		$this->assertEqual('/lithium/app/webroot/css/style.css', $result);
	}
}

?>