<?php 
namespace Symfony\Component\Routing
{
interface RequestContextAwareInterface
{
public function setContext(RequestContext $context);
public function getContext();
}
}
namespace Symfony\Component\Routing\Generator
{
use Symfony\Component\Routing\Exception\InvalidParameterException;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RequestContextAwareInterface;
interface UrlGeneratorInterface extends RequestContextAwareInterface
{
const ABSOLUTE_URL = 0;
const ABSOLUTE_PATH = 1;
const RELATIVE_PATH = 2;
const NETWORK_PATH = 3;
public function generate($name, $parameters = array(), $referenceType = self::ABSOLUTE_PATH);
}
}
namespace Symfony\Component\Routing\Generator
{
interface ConfigurableRequirementsInterface
{
public function setStrictRequirements($enabled);
public function isStrictRequirements();
}
}
namespace Symfony\Component\Routing\Generator
{
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Exception\InvalidParameterException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Psr\Log\LoggerInterface;
class UrlGenerator implements UrlGeneratorInterface, ConfigurableRequirementsInterface
{
protected $routes;
protected $context;
protected $strictRequirements = true;
protected $logger;
protected $decodedChars = array('%2F'=>'/','%40'=>'@','%3A'=>':','%3B'=>';','%2C'=>',','%3D'=>'=','%2B'=>'+','%21'=>'!','%2A'=>'*','%7C'=>'|',
);
public function __construct(RouteCollection $routes, RequestContext $context, LoggerInterface $logger = null)
{
$this->routes = $routes;
$this->context = $context;
$this->logger = $logger;
}
public function setContext(RequestContext $context)
{
$this->context = $context;
}
public function getContext()
{
return $this->context;
}
public function setStrictRequirements($enabled)
{
$this->strictRequirements = null === $enabled ? null : (bool) $enabled;
}
public function isStrictRequirements()
{
return $this->strictRequirements;
}
public function generate($name, $parameters = array(), $referenceType = self::ABSOLUTE_PATH)
{
if (null === $route = $this->routes->get($name)) {
throw new RouteNotFoundException(sprintf('Unable to generate a URL for the named route "%s" as such route does not exist.', $name));
}
$compiledRoute = $route->compile();
return $this->doGenerate($compiledRoute->getVariables(), $route->getDefaults(), $route->getRequirements(), $compiledRoute->getTokens(), $parameters, $name, $referenceType, $compiledRoute->getHostTokens(), $route->getSchemes());
}
protected function doGenerate($variables, $defaults, $requirements, $tokens, $parameters, $name, $referenceType, $hostTokens, array $requiredSchemes = array())
{
$variables = array_flip($variables);
$mergedParams = array_replace($defaults, $this->context->getParameters(), $parameters);
if ($diff = array_diff_key($variables, $mergedParams)) {
throw new MissingMandatoryParametersException(sprintf('Some mandatory parameters are missing ("%s") to generate a URL for route "%s".', implode('", "', array_keys($diff)), $name));
}
$url ='';
$optional = true;
foreach ($tokens as $token) {
if ('variable'=== $token[0]) {
if (!$optional || !array_key_exists($token[3], $defaults) || null !== $mergedParams[$token[3]] && (string) $mergedParams[$token[3]] !== (string) $defaults[$token[3]]) {
if (null !== $this->strictRequirements && !preg_match('#^'.$token[2].'$#', $mergedParams[$token[3]])) {
$message = sprintf('Parameter "%s" for route "%s" must match "%s" ("%s" given) to generate a corresponding URL.', $token[3], $name, $token[2], $mergedParams[$token[3]]);
if ($this->strictRequirements) {
throw new InvalidParameterException($message);
}
if ($this->logger) {
$this->logger->error($message);
}
return;
}
$url = $token[1].$mergedParams[$token[3]].$url;
$optional = false;
}
} else {
$url = $token[1].$url;
$optional = false;
}
}
if (''=== $url) {
$url ='/';
}
$url = strtr(rawurlencode($url), $this->decodedChars);
$url = strtr($url, array('/../'=>'/%2E%2E/','/./'=>'/%2E/'));
if ('/..'=== substr($url, -3)) {
$url = substr($url, 0, -2).'%2E%2E';
} elseif ('/.'=== substr($url, -2)) {
$url = substr($url, 0, -1).'%2E';
}
$schemeAuthority ='';
if ($host = $this->context->getHost()) {
$scheme = $this->context->getScheme();
if ($requiredSchemes) {
$schemeMatched = false;
foreach ($requiredSchemes as $requiredScheme) {
if ($scheme === $requiredScheme) {
$schemeMatched = true;
break;
}
}
if (!$schemeMatched) {
$referenceType = self::ABSOLUTE_URL;
$scheme = current($requiredSchemes);
}
}
if ($hostTokens) {
$routeHost ='';
foreach ($hostTokens as $token) {
if ('variable'=== $token[0]) {
if (null !== $this->strictRequirements && !preg_match('#^'.$token[2].'$#i', $mergedParams[$token[3]])) {
$message = sprintf('Parameter "%s" for route "%s" must match "%s" ("%s" given) to generate a corresponding URL.', $token[3], $name, $token[2], $mergedParams[$token[3]]);
if ($this->strictRequirements) {
throw new InvalidParameterException($message);
}
if ($this->logger) {
$this->logger->error($message);
}
return;
}
$routeHost = $token[1].$mergedParams[$token[3]].$routeHost;
} else {
$routeHost = $token[1].$routeHost;
}
}
if ($routeHost !== $host) {
$host = $routeHost;
if (self::ABSOLUTE_URL !== $referenceType) {
$referenceType = self::NETWORK_PATH;
}
}
}
if (self::ABSOLUTE_URL === $referenceType || self::NETWORK_PATH === $referenceType) {
$port ='';
if ('http'=== $scheme && 80 != $this->context->getHttpPort()) {
$port =':'.$this->context->getHttpPort();
} elseif ('https'=== $scheme && 443 != $this->context->getHttpsPort()) {
$port =':'.$this->context->getHttpsPort();
}
$schemeAuthority = self::NETWORK_PATH === $referenceType ?'//': "$scheme://";
$schemeAuthority .= $host.$port;
}
}
if (self::RELATIVE_PATH === $referenceType) {
$url = self::getRelativePath($this->context->getPathInfo(), $url);
} else {
$url = $schemeAuthority.$this->context->getBaseUrl().$url;
}
$extra = array_diff_key($parameters, $variables, $defaults);
if ($extra && $query = http_build_query($extra,'','&')) {
$url .='?'.strtr($query, array('%2F'=>'/'));
}
return $url;
}
public static function getRelativePath($basePath, $targetPath)
{
if ($basePath === $targetPath) {
return'';
}
$sourceDirs = explode('/', isset($basePath[0]) &&'/'=== $basePath[0] ? substr($basePath, 1) : $basePath);
$targetDirs = explode('/', isset($targetPath[0]) &&'/'=== $targetPath[0] ? substr($targetPath, 1) : $targetPath);
array_pop($sourceDirs);
$targetFile = array_pop($targetDirs);
foreach ($sourceDirs as $i => $dir) {
if (isset($targetDirs[$i]) && $dir === $targetDirs[$i]) {
unset($sourceDirs[$i], $targetDirs[$i]);
} else {
break;
}
}
$targetDirs[] = $targetFile;
$path = str_repeat('../', count($sourceDirs)).implode('/', $targetDirs);
return''=== $path ||'/'=== $path[0]
|| false !== ($colonPos = strpos($path,':')) && ($colonPos < ($slashPos = strpos($path,'/')) || false === $slashPos)
? "./$path" : $path;
}
}
}
namespace Symfony\Component\Routing
{
use Symfony\Component\HttpFoundation\Request;
class RequestContext
{
private $baseUrl;
private $pathInfo;
private $method;
private $host;
private $scheme;
private $httpPort;
private $httpsPort;
private $queryString;
private $parameters = array();
public function __construct($baseUrl ='', $method ='GET', $host ='localhost', $scheme ='http', $httpPort = 80, $httpsPort = 443, $path ='/', $queryString ='')
{
$this->setBaseUrl($baseUrl);
$this->setMethod($method);
$this->setHost($host);
$this->setScheme($scheme);
$this->setHttpPort($httpPort);
$this->setHttpsPort($httpsPort);
$this->setPathInfo($path);
$this->setQueryString($queryString);
}
public function fromRequest(Request $request)
{
$this->setBaseUrl($request->getBaseUrl());
$this->setPathInfo($request->getPathInfo());
$this->setMethod($request->getMethod());
$this->setHost($request->getHost());
$this->setScheme($request->getScheme());
$this->setHttpPort($request->isSecure() ? $this->httpPort : $request->getPort());
$this->setHttpsPort($request->isSecure() ? $request->getPort() : $this->httpsPort);
$this->setQueryString($request->server->get('QUERY_STRING',''));
return $this;
}
public function getBaseUrl()
{
return $this->baseUrl;
}
public function setBaseUrl($baseUrl)
{
$this->baseUrl = $baseUrl;
return $this;
}
public function getPathInfo()
{
return $this->pathInfo;
}
public function setPathInfo($pathInfo)
{
$this->pathInfo = $pathInfo;
return $this;
}
public function getMethod()
{
return $this->method;
}
public function setMethod($method)
{
$this->method = strtoupper($method);
return $this;
}
public function getHost()
{
return $this->host;
}
public function setHost($host)
{
$this->host = strtolower($host);
return $this;
}
public function getScheme()
{
return $this->scheme;
}
public function setScheme($scheme)
{
$this->scheme = strtolower($scheme);
return $this;
}
public function getHttpPort()
{
return $this->httpPort;
}
public function setHttpPort($httpPort)
{
$this->httpPort = (int) $httpPort;
return $this;
}
public function getHttpsPort()
{
return $this->httpsPort;
}
public function setHttpsPort($httpsPort)
{
$this->httpsPort = (int) $httpsPort;
return $this;
}
public function getQueryString()
{
return $this->queryString;
}
public function setQueryString($queryString)
{
$this->queryString = (string) $queryString;
return $this;
}
public function getParameters()
{
return $this->parameters;
}
public function setParameters(array $parameters)
{
$this->parameters = $parameters;
return $this;
}
public function getParameter($name)
{
return isset($this->parameters[$name]) ? $this->parameters[$name] : null;
}
public function hasParameter($name)
{
return array_key_exists($name, $this->parameters);
}
public function setParameter($name, $parameter)
{
$this->parameters[$name] = $parameter;
return $this;
}
}
}
namespace Symfony\Component\Routing\Matcher
{
use Symfony\Component\Routing\RequestContextAwareInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
interface UrlMatcherInterface extends RequestContextAwareInterface
{
public function match($pathinfo);
}
}
namespace Symfony\Component\Routing
{
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
interface RouterInterface extends UrlMatcherInterface, UrlGeneratorInterface
{
public function getRouteCollection();
}
}
namespace Symfony\Component\Routing\Matcher
{
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
interface RequestMatcherInterface
{
public function matchRequest(Request $request);
}
}
namespace Symfony\Component\Routing
{
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\ConfigCacheInterface;
use Symfony\Component\Config\ConfigCacheFactoryInterface;
use Symfony\Component\Config\ConfigCacheFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\ConfigurableRequirementsInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Generator\Dumper\GeneratorDumperInterface;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Component\Routing\Matcher\Dumper\MatcherDumperInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;
class Router implements RouterInterface, RequestMatcherInterface
{
protected $matcher;
protected $generator;
protected $context;
protected $loader;
protected $collection;
protected $resource;
protected $options = array();
protected $logger;
private $configCacheFactory;
private $expressionLanguageProviders = array();
public function __construct(LoaderInterface $loader, $resource, array $options = array(), RequestContext $context = null, LoggerInterface $logger = null)
{
$this->loader = $loader;
$this->resource = $resource;
$this->logger = $logger;
$this->context = $context ?: new RequestContext();
$this->setOptions($options);
}
public function setOptions(array $options)
{
$this->options = array('cache_dir'=> null,'debug'=> false,'generator_class'=>'Symfony\\Component\\Routing\\Generator\\UrlGenerator','generator_base_class'=>'Symfony\\Component\\Routing\\Generator\\UrlGenerator','generator_dumper_class'=>'Symfony\\Component\\Routing\\Generator\\Dumper\\PhpGeneratorDumper','generator_cache_class'=>'ProjectUrlGenerator','matcher_class'=>'Symfony\\Component\\Routing\\Matcher\\UrlMatcher','matcher_base_class'=>'Symfony\\Component\\Routing\\Matcher\\UrlMatcher','matcher_dumper_class'=>'Symfony\\Component\\Routing\\Matcher\\Dumper\\PhpMatcherDumper','matcher_cache_class'=>'ProjectUrlMatcher','resource_type'=> null,'strict_requirements'=> true,
);
$invalid = array();
foreach ($options as $key => $value) {
if (array_key_exists($key, $this->options)) {
$this->options[$key] = $value;
} else {
$invalid[] = $key;
}
}
if ($invalid) {
throw new \InvalidArgumentException(sprintf('The Router does not support the following options: "%s".', implode('", "', $invalid)));
}
}
public function setOption($key, $value)
{
if (!array_key_exists($key, $this->options)) {
throw new \InvalidArgumentException(sprintf('The Router does not support the "%s" option.', $key));
}
$this->options[$key] = $value;
}
public function getOption($key)
{
if (!array_key_exists($key, $this->options)) {
throw new \InvalidArgumentException(sprintf('The Router does not support the "%s" option.', $key));
}
return $this->options[$key];
}
public function getRouteCollection()
{
if (null === $this->collection) {
$this->collection = $this->loader->load($this->resource, $this->options['resource_type']);
}
return $this->collection;
}
public function setContext(RequestContext $context)
{
$this->context = $context;
if (null !== $this->matcher) {
$this->getMatcher()->setContext($context);
}
if (null !== $this->generator) {
$this->getGenerator()->setContext($context);
}
}
public function getContext()
{
return $this->context;
}
public function setConfigCacheFactory(ConfigCacheFactoryInterface $configCacheFactory)
{
$this->configCacheFactory = $configCacheFactory;
}
public function generate($name, $parameters = array(), $referenceType = self::ABSOLUTE_PATH)
{
return $this->getGenerator()->generate($name, $parameters, $referenceType);
}
public function match($pathinfo)
{
return $this->getMatcher()->match($pathinfo);
}
public function matchRequest(Request $request)
{
$matcher = $this->getMatcher();
if (!$matcher instanceof RequestMatcherInterface) {
return $matcher->match($request->getPathInfo());
}
return $matcher->matchRequest($request);
}
public function getMatcher()
{
if (null !== $this->matcher) {
return $this->matcher;
}
if (null === $this->options['cache_dir'] || null === $this->options['matcher_cache_class']) {
$this->matcher = new $this->options['matcher_class']($this->getRouteCollection(), $this->context);
if (method_exists($this->matcher,'addExpressionLanguageProvider')) {
foreach ($this->expressionLanguageProviders as $provider) {
$this->matcher->addExpressionLanguageProvider($provider);
}
}
return $this->matcher;
}
$cache = $this->getConfigCacheFactory()->cache($this->options['cache_dir'].'/'.$this->options['matcher_cache_class'].'.php',
function (ConfigCacheInterface $cache) {
$dumper = $this->getMatcherDumperInstance();
if (method_exists($dumper,'addExpressionLanguageProvider')) {
foreach ($this->expressionLanguageProviders as $provider) {
$dumper->addExpressionLanguageProvider($provider);
}
}
$options = array('class'=> $this->options['matcher_cache_class'],'base_class'=> $this->options['matcher_base_class'],
);
$cache->write($dumper->dump($options), $this->getRouteCollection()->getResources());
}
);
require_once $cache->getPath();
return $this->matcher = new $this->options['matcher_cache_class']($this->context);
}
public function getGenerator()
{
if (null !== $this->generator) {
return $this->generator;
}
if (null === $this->options['cache_dir'] || null === $this->options['generator_cache_class']) {
$this->generator = new $this->options['generator_class']($this->getRouteCollection(), $this->context, $this->logger);
} else {
$cache = $this->getConfigCacheFactory()->cache($this->options['cache_dir'].'/'.$this->options['generator_cache_class'].'.php',
function (ConfigCacheInterface $cache) {
$dumper = $this->getGeneratorDumperInstance();
$options = array('class'=> $this->options['generator_cache_class'],'base_class'=> $this->options['generator_base_class'],
);
$cache->write($dumper->dump($options), $this->getRouteCollection()->getResources());
}
);
require_once $cache->getPath();
$this->generator = new $this->options['generator_cache_class']($this->context, $this->logger);
}
if ($this->generator instanceof ConfigurableRequirementsInterface) {
$this->generator->setStrictRequirements($this->options['strict_requirements']);
}
return $this->generator;
}
public function addExpressionLanguageProvider(ExpressionFunctionProviderInterface $provider)
{
$this->expressionLanguageProviders[] = $provider;
}
protected function getGeneratorDumperInstance()
{
return new $this->options['generator_dumper_class']($this->getRouteCollection());
}
protected function getMatcherDumperInstance()
{
return new $this->options['matcher_dumper_class']($this->getRouteCollection());
}
private function getConfigCacheFactory()
{
if (null === $this->configCacheFactory) {
$this->configCacheFactory = new ConfigCacheFactory($this->options['debug']);
}
return $this->configCacheFactory;
}
}
}
namespace Symfony\Component\Routing\Matcher
{
interface RedirectableUrlMatcherInterface
{
public function redirect($path, $route, $scheme = null);
}
}
namespace Symfony\Component\Routing\Matcher
{
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;
class UrlMatcher implements UrlMatcherInterface, RequestMatcherInterface
{
const REQUIREMENT_MATCH = 0;
const REQUIREMENT_MISMATCH = 1;
const ROUTE_MATCH = 2;
protected $context;
protected $allow = array();
protected $routes;
protected $request;
protected $expressionLanguage;
protected $expressionLanguageProviders = array();
public function __construct(RouteCollection $routes, RequestContext $context)
{
$this->routes = $routes;
$this->context = $context;
}
public function setContext(RequestContext $context)
{
$this->context = $context;
}
public function getContext()
{
return $this->context;
}
public function match($pathinfo)
{
$this->allow = array();
if ($ret = $this->matchCollection(rawurldecode($pathinfo), $this->routes)) {
return $ret;
}
throw 0 < count($this->allow)
? new MethodNotAllowedException(array_unique($this->allow))
: new ResourceNotFoundException(sprintf('No routes found for "%s".', $pathinfo));
}
public function matchRequest(Request $request)
{
$this->request = $request;
$ret = $this->match($request->getPathInfo());
$this->request = null;
return $ret;
}
public function addExpressionLanguageProvider(ExpressionFunctionProviderInterface $provider)
{
$this->expressionLanguageProviders[] = $provider;
}
protected function matchCollection($pathinfo, RouteCollection $routes)
{
foreach ($routes as $name => $route) {
$compiledRoute = $route->compile();
if (''!== $compiledRoute->getStaticPrefix() && 0 !== strpos($pathinfo, $compiledRoute->getStaticPrefix())) {
continue;
}
if (!preg_match($compiledRoute->getRegex(), $pathinfo, $matches)) {
continue;
}
$hostMatches = array();
if ($compiledRoute->getHostRegex() && !preg_match($compiledRoute->getHostRegex(), $this->context->getHost(), $hostMatches)) {
continue;
}
if ($requiredMethods = $route->getMethods()) {
if ('HEAD'=== $method = $this->context->getMethod()) {
$method ='GET';
}
if (!in_array($method, $requiredMethods)) {
$this->allow = array_merge($this->allow, $requiredMethods);
continue;
}
}
$status = $this->handleRouteRequirements($pathinfo, $name, $route);
if (self::ROUTE_MATCH === $status[0]) {
return $status[1];
}
if (self::REQUIREMENT_MISMATCH === $status[0]) {
continue;
}
return $this->getAttributes($route, $name, array_replace($matches, $hostMatches));
}
}
protected function getAttributes(Route $route, $name, array $attributes)
{
$attributes['_route'] = $name;
return $this->mergeDefaults($attributes, $route->getDefaults());
}
protected function handleRouteRequirements($pathinfo, $name, Route $route)
{
if ($route->getCondition() && !$this->getExpressionLanguage()->evaluate($route->getCondition(), array('context'=> $this->context,'request'=> $this->request))) {
return array(self::REQUIREMENT_MISMATCH, null);
}
$scheme = $this->context->getScheme();
$status = $route->getSchemes() && !$route->hasScheme($scheme) ? self::REQUIREMENT_MISMATCH : self::REQUIREMENT_MATCH;
return array($status, null);
}
protected function mergeDefaults($params, $defaults)
{
foreach ($params as $key => $value) {
if (!is_int($key)) {
$defaults[$key] = $value;
}
}
return $defaults;
}
protected function getExpressionLanguage()
{
if (null === $this->expressionLanguage) {
if (!class_exists('Symfony\Component\ExpressionLanguage\ExpressionLanguage')) {
throw new \RuntimeException('Unable to use expressions as the Symfony ExpressionLanguage component is not installed.');
}
$this->expressionLanguage = new ExpressionLanguage(null, $this->expressionLanguageProviders);
}
return $this->expressionLanguage;
}
}
}
namespace Symfony\Component\Routing\Matcher
{
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Route;
abstract class RedirectableUrlMatcher extends UrlMatcher implements RedirectableUrlMatcherInterface
{
public function match($pathinfo)
{
try {
$parameters = parent::match($pathinfo);
} catch (ResourceNotFoundException $e) {
if ('/'=== substr($pathinfo, -1) || !in_array($this->context->getMethod(), array('HEAD','GET'))) {
throw $e;
}
try {
parent::match($pathinfo.'/');
return $this->redirect($pathinfo.'/', null);
} catch (ResourceNotFoundException $e2) {
throw $e;
}
}
return $parameters;
}
protected function handleRouteRequirements($pathinfo, $name, Route $route)
{
if ($route->getCondition() && !$this->getExpressionLanguage()->evaluate($route->getCondition(), array('context'=> $this->context,'request'=> $this->request))) {
return array(self::REQUIREMENT_MISMATCH, null);
}
$scheme = $this->context->getScheme();
$schemes = $route->getSchemes();
if ($schemes && !$route->hasScheme($scheme)) {
return array(self::ROUTE_MATCH, $this->redirect($pathinfo, $name, current($schemes)));
}
return array(self::REQUIREMENT_MATCH, null);
}
}
}
namespace Symfony\Bundle\FrameworkBundle\Routing
{
use Symfony\Component\Routing\Matcher\RedirectableUrlMatcher as BaseMatcher;
class RedirectableUrlMatcher extends BaseMatcher
{
public function redirect($path, $route, $scheme = null)
{
return array('_controller'=>'Symfony\\Bundle\\FrameworkBundle\\Controller\\RedirectController::urlRedirectAction','path'=> $path,'permanent'=> true,'scheme'=> $scheme,'httpPort'=> $this->context->getHttpPort(),'httpsPort'=> $this->context->getHttpsPort(),'_route'=> $route,
);
}
}
}
namespace Symfony\Component\HttpKernel\CacheWarmer
{
interface WarmableInterface
{
public function warmUp($cacheDir);
}
}
namespace Symfony\Bundle\FrameworkBundle\Routing
{
use Symfony\Component\Routing\Router as BaseRouter;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
class Router extends BaseRouter implements WarmableInterface
{
private $container;
public function __construct(ContainerInterface $container, $resource, array $options = array(), RequestContext $context = null)
{
$this->container = $container;
$this->resource = $resource;
$this->context = $context ?: new RequestContext();
$this->setOptions($options);
}
public function getRouteCollection()
{
if (null === $this->collection) {
$this->collection = $this->container->get('routing.loader')->load($this->resource, $this->options['resource_type']);
$this->resolveParameters($this->collection);
}
return $this->collection;
}
public function warmUp($cacheDir)
{
$currentDir = $this->getOption('cache_dir');
$this->setOption('cache_dir', $cacheDir);
$this->getMatcher();
$this->getGenerator();
$this->setOption('cache_dir', $currentDir);
}
private function resolveParameters(RouteCollection $collection)
{
foreach ($collection as $route) {
foreach ($route->getDefaults() as $name => $value) {
$route->setDefault($name, $this->resolve($value));
}
foreach ($route->getRequirements() as $name => $value) {
$route->setRequirement($name, $this->resolve($value));
}
$route->setPath($this->resolve($route->getPath()));
$route->setHost($this->resolve($route->getHost()));
$schemes = array();
foreach ($route->getSchemes() as $scheme) {
$schemes = array_merge($schemes, explode('|', $this->resolve($scheme)));
}
$route->setSchemes($schemes);
$methods = array();
foreach ($route->getMethods() as $method) {
$methods = array_merge($methods, explode('|', $this->resolve($method)));
}
$route->setMethods($methods);
$route->setCondition($this->resolve($route->getCondition()));
}
}
private function resolve($value)
{
if (is_array($value)) {
foreach ($value as $key => $val) {
$value[$key] = $this->resolve($val);
}
return $value;
}
if (!is_string($value)) {
return $value;
}
$container = $this->container;
$escapedValue = preg_replace_callback('/%%|%([^%\s]++)%/', function ($match) use ($container, $value) {
if (!isset($match[1])) {
return'%%';
}
$resolved = $container->getParameter($match[1]);
if (is_string($resolved) || is_numeric($resolved)) {
return (string) $resolved;
}
throw new RuntimeException(sprintf('The container parameter "%s", used in the route configuration value "%s", '.'must be a string or numeric, but it is of type %s.',
$match[1],
$value,
gettype($resolved)
)
);
}, $value);
return str_replace('%%','%', $escapedValue);
}
}
}
namespace Symfony\Component\Config
{
interface FileLocatorInterface
{
public function locate($name, $currentPath = null, $first = true);
}
}
namespace Symfony\Component\Config
{
class FileLocator implements FileLocatorInterface
{
protected $paths;
public function __construct($paths = array())
{
$this->paths = (array) $paths;
}
public function locate($name, $currentPath = null, $first = true)
{
if (''== $name) {
throw new \InvalidArgumentException('An empty file name is not valid to be located.');
}
if ($this->isAbsolutePath($name)) {
if (!file_exists($name)) {
throw new \InvalidArgumentException(sprintf('The file "%s" does not exist.', $name));
}
return $name;
}
$paths = $this->paths;
if (null !== $currentPath) {
array_unshift($paths, $currentPath);
}
$paths = array_unique($paths);
$filepaths = array();
foreach ($paths as $path) {
if (file_exists($file = $path.DIRECTORY_SEPARATOR.$name)) {
if (true === $first) {
return $file;
}
$filepaths[] = $file;
}
}
if (!$filepaths) {
throw new \InvalidArgumentException(sprintf('The file "%s" does not exist (in: %s).', $name, implode(', ', $paths)));
}
return $filepaths;
}
private function isAbsolutePath($file)
{
if ($file[0] ==='/'|| $file[0] ==='\\'|| (strlen($file) > 3 && ctype_alpha($file[0])
&& $file[1] ===':'&& ($file[2] ==='\\'|| $file[2] ==='/')
)
|| null !== parse_url($file, PHP_URL_SCHEME)
) {
return true;
}
return false;
}
}
}
namespace Symfony\Component\Debug
{
use Psr\Log\LogLevel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Debug\Exception\ContextErrorException;
use Symfony\Component\Debug\Exception\FatalErrorException;
use Symfony\Component\Debug\Exception\FatalThrowableError;
use Symfony\Component\Debug\Exception\OutOfMemoryException;
use Symfony\Component\Debug\FatalErrorHandler\UndefinedFunctionFatalErrorHandler;
use Symfony\Component\Debug\FatalErrorHandler\UndefinedMethodFatalErrorHandler;
use Symfony\Component\Debug\FatalErrorHandler\ClassNotFoundFatalErrorHandler;
use Symfony\Component\Debug\FatalErrorHandler\FatalErrorHandlerInterface;
class ErrorHandler
{
private $levels = array(
E_DEPRECATED =>'Deprecated',
E_USER_DEPRECATED =>'User Deprecated',
E_NOTICE =>'Notice',
E_USER_NOTICE =>'User Notice',
E_STRICT =>'Runtime Notice',
E_WARNING =>'Warning',
E_USER_WARNING =>'User Warning',
E_COMPILE_WARNING =>'Compile Warning',
E_CORE_WARNING =>'Core Warning',
E_USER_ERROR =>'User Error',
E_RECOVERABLE_ERROR =>'Catchable Fatal Error',
E_COMPILE_ERROR =>'Compile Error',
E_PARSE =>'Parse Error',
E_ERROR =>'Error',
E_CORE_ERROR =>'Core Error',
);
private $loggers = array(
E_DEPRECATED => array(null, LogLevel::INFO),
E_USER_DEPRECATED => array(null, LogLevel::INFO),
E_NOTICE => array(null, LogLevel::WARNING),
E_USER_NOTICE => array(null, LogLevel::WARNING),
E_STRICT => array(null, LogLevel::WARNING),
E_WARNING => array(null, LogLevel::WARNING),
E_USER_WARNING => array(null, LogLevel::WARNING),
E_COMPILE_WARNING => array(null, LogLevel::WARNING),
E_CORE_WARNING => array(null, LogLevel::WARNING),
E_USER_ERROR => array(null, LogLevel::CRITICAL),
E_RECOVERABLE_ERROR => array(null, LogLevel::CRITICAL),
E_COMPILE_ERROR => array(null, LogLevel::CRITICAL),
E_PARSE => array(null, LogLevel::CRITICAL),
E_ERROR => array(null, LogLevel::CRITICAL),
E_CORE_ERROR => array(null, LogLevel::CRITICAL),
);
private $thrownErrors = 0x1FFF; private $scopedErrors = 0x1FFF; private $tracedErrors = 0x77FB; private $screamedErrors = 0x55; private $loggedErrors = 0;
private $loggedTraces = array();
private $isRecursive = 0;
private $isRoot = false;
private $exceptionHandler;
private $bootstrappingLogger;
private static $reservedMemory;
private static $stackedErrors = array();
private static $stackedErrorLevels = array();
private static $toStringException = null;
public static function register(self $handler = null, $replace = true)
{
if (null === self::$reservedMemory) {
self::$reservedMemory = str_repeat('x', 10240);
register_shutdown_function(__CLASS__.'::handleFatalError');
}
if ($handlerIsNew = null === $handler) {
$handler = new static();
}
if (null === $prev = set_error_handler(array($handler,'handleError'))) {
restore_error_handler();
set_error_handler(array($handler,'handleError'), $handler->thrownErrors | $handler->loggedErrors);
$handler->isRoot = true;
}
if ($handlerIsNew && is_array($prev) && $prev[0] instanceof self) {
$handler = $prev[0];
$replace = false;
}
if ($replace || !$prev) {
$handler->setExceptionHandler(set_exception_handler(array($handler,'handleException')));
} else {
restore_error_handler();
}
$handler->throwAt(E_ALL & $handler->thrownErrors, true);
return $handler;
}
public function __construct(BufferingLogger $bootstrappingLogger = null)
{
if ($bootstrappingLogger) {
$this->bootstrappingLogger = $bootstrappingLogger;
$this->setDefaultLogger($bootstrappingLogger);
}
}
public function setDefaultLogger(LoggerInterface $logger, $levels = E_ALL, $replace = false)
{
$loggers = array();
if (is_array($levels)) {
foreach ($levels as $type => $logLevel) {
if (empty($this->loggers[$type][0]) || $replace || $this->loggers[$type][0] === $this->bootstrappingLogger) {
$loggers[$type] = array($logger, $logLevel);
}
}
} else {
if (null === $levels) {
$levels = E_ALL;
}
foreach ($this->loggers as $type => $log) {
if (($type & $levels) && (empty($log[0]) || $replace || $log[0] === $this->bootstrappingLogger)) {
$log[0] = $logger;
$loggers[$type] = $log;
}
}
}
$this->setLoggers($loggers);
}
public function setLoggers(array $loggers)
{
$prevLogged = $this->loggedErrors;
$prev = $this->loggers;
$flush = array();
foreach ($loggers as $type => $log) {
if (!isset($prev[$type])) {
throw new \InvalidArgumentException('Unknown error type: '.$type);
}
if (!is_array($log)) {
$log = array($log);
} elseif (!array_key_exists(0, $log)) {
throw new \InvalidArgumentException('No logger provided');
}
if (null === $log[0]) {
$this->loggedErrors &= ~$type;
} elseif ($log[0] instanceof LoggerInterface) {
$this->loggedErrors |= $type;
} else {
throw new \InvalidArgumentException('Invalid logger provided');
}
$this->loggers[$type] = $log + $prev[$type];
if ($this->bootstrappingLogger && $prev[$type][0] === $this->bootstrappingLogger) {
$flush[$type] = $type;
}
}
$this->reRegister($prevLogged | $this->thrownErrors);
if ($flush) {
foreach ($this->bootstrappingLogger->cleanLogs() as $log) {
$type = $log[2]['type'];
if (!isset($flush[$type])) {
$this->bootstrappingLogger->log($log[0], $log[1], $log[2]);
} elseif ($this->loggers[$type][0]) {
$this->loggers[$type][0]->log($this->loggers[$type][1], $log[1], $log[2]);
}
}
}
return $prev;
}
public function setExceptionHandler(callable $handler = null)
{
$prev = $this->exceptionHandler;
$this->exceptionHandler = $handler;
return $prev;
}
public function throwAt($levels, $replace = false)
{
$prev = $this->thrownErrors;
$this->thrownErrors = ($levels | E_RECOVERABLE_ERROR | E_USER_ERROR) & ~E_USER_DEPRECATED & ~E_DEPRECATED;
if (!$replace) {
$this->thrownErrors |= $prev;
}
$this->reRegister($prev | $this->loggedErrors);
return $prev;
}
public function scopeAt($levels, $replace = false)
{
$prev = $this->scopedErrors;
$this->scopedErrors = (int) $levels;
if (!$replace) {
$this->scopedErrors |= $prev;
}
return $prev;
}
public function traceAt($levels, $replace = false)
{
$prev = $this->tracedErrors;
$this->tracedErrors = (int) $levels;
if (!$replace) {
$this->tracedErrors |= $prev;
}
return $prev;
}
public function screamAt($levels, $replace = false)
{
$prev = $this->screamedErrors;
$this->screamedErrors = (int) $levels;
if (!$replace) {
$this->screamedErrors |= $prev;
}
return $prev;
}
private function reRegister($prev)
{
if ($prev !== $this->thrownErrors | $this->loggedErrors) {
$handler = set_error_handler('var_dump');
$handler = is_array($handler) ? $handler[0] : null;
restore_error_handler();
if ($handler === $this) {
restore_error_handler();
if ($this->isRoot) {
set_error_handler(array($this,'handleError'), $this->thrownErrors | $this->loggedErrors);
} else {
set_error_handler(array($this,'handleError'));
}
}
}
}
public function handleError($type, $message, $file, $line, array $context, array $backtrace = null)
{
$level = error_reporting() | E_RECOVERABLE_ERROR | E_USER_ERROR | E_DEPRECATED | E_USER_DEPRECATED;
$log = $this->loggedErrors & $type;
$throw = $this->thrownErrors & $type & $level;
$type &= $level | $this->screamedErrors;
if (!$type || (!$log && !$throw)) {
return $type && $log;
}
if (null !== $backtrace && $type & E_ERROR) {
$this->handleFatalError(compact('type','message','file','line','backtrace'));
return true;
}
if ($throw) {
if (null !== self::$toStringException) {
$throw = self::$toStringException;
self::$toStringException = null;
} elseif (($this->scopedErrors & $type) && class_exists(ContextErrorException::class)) {
$throw = new ContextErrorException($this->levels[$type].': '.$message, 0, $type, $file, $line, $context);
} else {
$throw = new \ErrorException($this->levels[$type].': '.$message, 0, $type, $file, $line);
}
if (E_USER_ERROR & $type) {
$backtrace = $backtrace ?: $throw->getTrace();
for ($i = 1; isset($backtrace[$i]); ++$i) {
if (isset($backtrace[$i]['function'], $backtrace[$i]['type'], $backtrace[$i - 1]['function'])
&&'__toString'=== $backtrace[$i]['function']
&&'->'=== $backtrace[$i]['type']
&& !isset($backtrace[$i - 1]['class'])
&& ('trigger_error'=== $backtrace[$i - 1]['function'] ||'user_error'=== $backtrace[$i - 1]['function'])
) {
foreach ($context as $e) {
if (($e instanceof \Exception || $e instanceof \Throwable) && $e->__toString() === $message) {
if (1 === $i) {
$throw = $e;
break;
}
self::$toStringException = $e;
return true;
}
}
if (1 < $i) {
$this->handleException($throw);
return false;
}
}
}
}
throw $throw;
}
$e = md5("{$type}/{$line}/{$file}\x00{$message}", true);
$trace = true;
if (!($this->tracedErrors & $type) || isset($this->loggedTraces[$e])) {
$trace = false;
} else {
$this->loggedTraces[$e] = 1;
}
$e = compact('type','file','line','level');
if ($type & $level) {
if ($this->scopedErrors & $type) {
$e['scope_vars'] = $context;
if ($trace) {
$e['stack'] = $backtrace ?: debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);
}
} elseif ($trace) {
if (null === $backtrace) {
$e['stack'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
} else {
foreach ($backtrace as &$frame) {
unset($frame['args'], $frame);
}
$e['stack'] = $backtrace;
}
}
}
if ($this->isRecursive) {
$log = 0;
} elseif (self::$stackedErrorLevels) {
self::$stackedErrors[] = array($this->loggers[$type][0], ($type & $level) ? $this->loggers[$type][1] : LogLevel::DEBUG, $message, $e);
} else {
try {
$this->isRecursive = true;
$this->loggers[$type][0]->log(($type & $level) ? $this->loggers[$type][1] : LogLevel::DEBUG, $message, $e);
} finally {
$this->isRecursive = false;
}
}
return $type && $log;
}
public function handleException($exception, array $error = null)
{
if (!$exception instanceof \Exception) {
$exception = new FatalThrowableError($exception);
}
$type = $exception instanceof FatalErrorException ? $exception->getSeverity() : E_ERROR;
if ($this->loggedErrors & $type) {
$e = array('type'=> $type,'file'=> $exception->getFile(),'line'=> $exception->getLine(),'level'=> error_reporting(),'stack'=> $exception->getTrace(),
);
if ($exception instanceof FatalErrorException) {
if ($exception instanceof FatalThrowableError) {
$error = array('type'=> $type,'message'=> $message = $exception->getMessage(),'file'=> $e['file'],'line'=> $e['line'],
);
} else {
$message ='Fatal '.$exception->getMessage();
}
} elseif ($exception instanceof \ErrorException) {
$message ='Uncaught '.$exception->getMessage();
if ($exception instanceof ContextErrorException) {
$e['context'] = $exception->getContext();
}
} else {
$message ='Uncaught Exception: '.$exception->getMessage();
}
if ($this->loggedErrors & $e['type']) {
$this->loggers[$e['type']][0]->log($this->loggers[$e['type']][1], $message, $e);
}
}
if ($exception instanceof FatalErrorException && !$exception instanceof OutOfMemoryException && $error) {
foreach ($this->getFatalErrorHandlers() as $handler) {
if ($e = $handler->handleError($error, $exception)) {
$exception = $e;
break;
}
}
}
if (empty($this->exceptionHandler)) {
throw $exception; }
try {
call_user_func($this->exceptionHandler, $exception);
} catch (\Exception $handlerException) {
} catch (\Throwable $handlerException) {
}
if (isset($handlerException)) {
$this->exceptionHandler = null;
$this->handleException($handlerException);
}
}
public static function handleFatalError(array $error = null)
{
if (null === self::$reservedMemory) {
return;
}
self::$reservedMemory = null;
$handler = set_error_handler('var_dump');
$handler = is_array($handler) ? $handler[0] : null;
restore_error_handler();
if (!$handler instanceof self) {
return;
}
if (null === $error) {
$error = error_get_last();
}
try {
while (self::$stackedErrorLevels) {
static::unstackErrors();
}
} catch (\Exception $exception) {
}
if ($error && $error['type'] &= E_PARSE | E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR) {
$handler->throwAt(0, true);
$trace = isset($error['backtrace']) ? $error['backtrace'] : null;
if (0 === strpos($error['message'],'Allowed memory') || 0 === strpos($error['message'],'Out of memory')) {
$exception = new OutOfMemoryException($handler->levels[$error['type']].': '.$error['message'], 0, $error['type'], $error['file'], $error['line'], 2, false, $trace);
} else {
$exception = new FatalErrorException($handler->levels[$error['type']].': '.$error['message'], 0, $error['type'], $error['file'], $error['line'], 2, true, $trace);
}
} elseif (!isset($exception)) {
return;
}
try {
$handler->handleException($exception, $error);
} catch (FatalErrorException $e) {
}
}
public static function stackErrors()
{
self::$stackedErrorLevels[] = error_reporting(error_reporting() | E_PARSE | E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR);
}
public static function unstackErrors()
{
$level = array_pop(self::$stackedErrorLevels);
if (null !== $level) {
$e = error_reporting($level);
if ($e !== ($level | E_PARSE | E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR)) {
error_reporting($e);
}
}
if (empty(self::$stackedErrorLevels)) {
$errors = self::$stackedErrors;
self::$stackedErrors = array();
foreach ($errors as $e) {
$e[0]->log($e[1], $e[2], $e[3]);
}
}
}
protected function getFatalErrorHandlers()
{
return array(
new UndefinedFunctionFatalErrorHandler(),
new UndefinedMethodFatalErrorHandler(),
new ClassNotFoundFatalErrorHandler(),
);
}
}
}
namespace Symfony\Component\EventDispatcher
{
class Event
{
private $propagationStopped = false;
public function isPropagationStopped()
{
return $this->propagationStopped;
}
public function stopPropagation()
{
$this->propagationStopped = true;
}
}
}
namespace Symfony\Component\EventDispatcher
{
interface EventDispatcherInterface
{
public function dispatch($eventName, Event $event = null);
public function addListener($eventName, $listener, $priority = 0);
public function addSubscriber(EventSubscriberInterface $subscriber);
public function removeListener($eventName, $listener);
public function removeSubscriber(EventSubscriberInterface $subscriber);
public function getListeners($eventName = null);
public function getListenerPriority($eventName, $listener);
public function hasListeners($eventName = null);
}
}
namespace Symfony\Component\EventDispatcher
{
class EventDispatcher implements EventDispatcherInterface
{
private $listeners = array();
private $sorted = array();
public function dispatch($eventName, Event $event = null)
{
if (null === $event) {
$event = new Event();
}
if ($listeners = $this->getListeners($eventName)) {
$this->doDispatch($listeners, $eventName, $event);
}
return $event;
}
public function getListeners($eventName = null)
{
if (null !== $eventName) {
if (!isset($this->listeners[$eventName])) {
return array();
}
if (!isset($this->sorted[$eventName])) {
$this->sortListeners($eventName);
}
return $this->sorted[$eventName];
}
foreach ($this->listeners as $eventName => $eventListeners) {
if (!isset($this->sorted[$eventName])) {
$this->sortListeners($eventName);
}
}
return array_filter($this->sorted);
}
public function getListenerPriority($eventName, $listener)
{
if (!isset($this->listeners[$eventName])) {
return;
}
foreach ($this->listeners[$eventName] as $priority => $listeners) {
if (false !== ($key = array_search($listener, $listeners, true))) {
return $priority;
}
}
}
public function hasListeners($eventName = null)
{
return (bool) count($this->getListeners($eventName));
}
public function addListener($eventName, $listener, $priority = 0)
{
$this->listeners[$eventName][$priority][] = $listener;
unset($this->sorted[$eventName]);
}
public function removeListener($eventName, $listener)
{
if (!isset($this->listeners[$eventName])) {
return;
}
foreach ($this->listeners[$eventName] as $priority => $listeners) {
if (false !== ($key = array_search($listener, $listeners, true))) {
unset($this->listeners[$eventName][$priority][$key], $this->sorted[$eventName]);
}
}
}
public function addSubscriber(EventSubscriberInterface $subscriber)
{
foreach ($subscriber->getSubscribedEvents() as $eventName => $params) {
if (is_string($params)) {
$this->addListener($eventName, array($subscriber, $params));
} elseif (is_string($params[0])) {
$this->addListener($eventName, array($subscriber, $params[0]), isset($params[1]) ? $params[1] : 0);
} else {
foreach ($params as $listener) {
$this->addListener($eventName, array($subscriber, $listener[0]), isset($listener[1]) ? $listener[1] : 0);
}
}
}
}
public function removeSubscriber(EventSubscriberInterface $subscriber)
{
foreach ($subscriber->getSubscribedEvents() as $eventName => $params) {
if (is_array($params) && is_array($params[0])) {
foreach ($params as $listener) {
$this->removeListener($eventName, array($subscriber, $listener[0]));
}
} else {
$this->removeListener($eventName, array($subscriber, is_string($params) ? $params : $params[0]));
}
}
}
protected function doDispatch($listeners, $eventName, Event $event)
{
foreach ($listeners as $listener) {
call_user_func($listener, $event, $eventName, $this);
if ($event->isPropagationStopped()) {
break;
}
}
}
private function sortListeners($eventName)
{
krsort($this->listeners[$eventName]);
$this->sorted[$eventName] = call_user_func_array('array_merge', $this->listeners[$eventName]);
}
}
}
namespace Symfony\Component\EventDispatcher
{
use Symfony\Component\DependencyInjection\ContainerInterface;
class ContainerAwareEventDispatcher extends EventDispatcher
{
private $container;
private $listenerIds = array();
private $listeners = array();
public function __construct(ContainerInterface $container)
{
$this->container = $container;
}
public function addListenerService($eventName, $callback, $priority = 0)
{
if (!is_array($callback) || 2 !== count($callback)) {
throw new \InvalidArgumentException('Expected an array("service", "method") argument');
}
$this->listenerIds[$eventName][] = array($callback[0], $callback[1], $priority);
}
public function removeListener($eventName, $listener)
{
$this->lazyLoad($eventName);
if (isset($this->listenerIds[$eventName])) {
foreach ($this->listenerIds[$eventName] as $i => list($serviceId, $method, $priority)) {
$key = $serviceId.'.'.$method;
if (isset($this->listeners[$eventName][$key]) && $listener === array($this->listeners[$eventName][$key], $method)) {
unset($this->listeners[$eventName][$key]);
if (empty($this->listeners[$eventName])) {
unset($this->listeners[$eventName]);
}
unset($this->listenerIds[$eventName][$i]);
if (empty($this->listenerIds[$eventName])) {
unset($this->listenerIds[$eventName]);
}
}
}
}
parent::removeListener($eventName, $listener);
}
public function hasListeners($eventName = null)
{
if (null === $eventName) {
return (bool) count($this->listenerIds) || (bool) count($this->listeners);
}
if (isset($this->listenerIds[$eventName])) {
return true;
}
return parent::hasListeners($eventName);
}
public function getListeners($eventName = null)
{
if (null === $eventName) {
foreach ($this->listenerIds as $serviceEventName => $args) {
$this->lazyLoad($serviceEventName);
}
} else {
$this->lazyLoad($eventName);
}
return parent::getListeners($eventName);
}
public function getListenerPriority($eventName, $listener)
{
$this->lazyLoad($eventName);
return parent::getListenerPriority($eventName, $listener);
}
public function addSubscriberService($serviceId, $class)
{
foreach ($class::getSubscribedEvents() as $eventName => $params) {
if (is_string($params)) {
$this->listenerIds[$eventName][] = array($serviceId, $params, 0);
} elseif (is_string($params[0])) {
$this->listenerIds[$eventName][] = array($serviceId, $params[0], isset($params[1]) ? $params[1] : 0);
} else {
foreach ($params as $listener) {
$this->listenerIds[$eventName][] = array($serviceId, $listener[0], isset($listener[1]) ? $listener[1] : 0);
}
}
}
}
public function getContainer()
{
return $this->container;
}
protected function lazyLoad($eventName)
{
if (isset($this->listenerIds[$eventName])) {
foreach ($this->listenerIds[$eventName] as list($serviceId, $method, $priority)) {
$listener = $this->container->get($serviceId);
$key = $serviceId.'.'.$method;
if (!isset($this->listeners[$eventName][$key])) {
$this->addListener($eventName, array($listener, $method), $priority);
} elseif ($listener !== $this->listeners[$eventName][$key]) {
parent::removeListener($eventName, array($this->listeners[$eventName][$key], $method));
$this->addListener($eventName, array($listener, $method), $priority);
}
$this->listeners[$eventName][$key] = $listener;
}
}
}
}
}
namespace Symfony\Component\EventDispatcher
{
interface EventSubscriberInterface
{
public static function getSubscribedEvents();
}
}
namespace Symfony\Component\HttpKernel\EventListener
{
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
class ResponseListener implements EventSubscriberInterface
{
private $charset;
public function __construct($charset)
{
$this->charset = $charset;
}
public function onKernelResponse(FilterResponseEvent $event)
{
if (!$event->isMasterRequest()) {
return;
}
$response = $event->getResponse();
if (null === $response->getCharset()) {
$response->setCharset($this->charset);
}
$response->prepare($event->getRequest());
}
public static function getSubscribedEvents()
{
return array(
KernelEvents::RESPONSE =>'onKernelResponse',
);
}
}
}
namespace Symfony\Component\HttpKernel\EventListener
{
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RequestContextAwareInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
class RouterListener implements EventSubscriberInterface
{
private $matcher;
private $context;
private $logger;
private $requestStack;
public function __construct($matcher, RequestStack $requestStack, RequestContext $context = null, LoggerInterface $logger = null)
{
if (!$matcher instanceof UrlMatcherInterface && !$matcher instanceof RequestMatcherInterface) {
throw new \InvalidArgumentException('Matcher must either implement UrlMatcherInterface or RequestMatcherInterface.');
}
if (null === $context && !$matcher instanceof RequestContextAwareInterface) {
throw new \InvalidArgumentException('You must either pass a RequestContext or the matcher must implement RequestContextAwareInterface.');
}
$this->matcher = $matcher;
$this->context = $context ?: $matcher->getContext();
$this->requestStack = $requestStack;
$this->logger = $logger;
}
private function setCurrentRequest(Request $request = null)
{
if (null !== $request) {
$this->context->fromRequest($request);
}
}
public function onKernelFinishRequest(FinishRequestEvent $event)
{
$this->setCurrentRequest($this->requestStack->getParentRequest());
}
public function onKernelRequest(GetResponseEvent $event)
{
$request = $event->getRequest();
$this->setCurrentRequest($request);
if ($request->attributes->has('_controller')) {
return;
}
try {
if ($this->matcher instanceof RequestMatcherInterface) {
$parameters = $this->matcher->matchRequest($request);
} else {
$parameters = $this->matcher->match($request->getPathInfo());
}
if (null !== $this->logger) {
$this->logger->info(sprintf('Matched route "%s".', isset($parameters['_route']) ? $parameters['_route'] :'n/a'), array('route_parameters'=> $parameters,'request_uri'=> $request->getUri(),
));
}
$request->attributes->add($parameters);
unset($parameters['_route'], $parameters['_controller']);
$request->attributes->set('_route_params', $parameters);
} catch (ResourceNotFoundException $e) {
$message = sprintf('No route found for "%s %s"', $request->getMethod(), $request->getPathInfo());
if ($referer = $request->headers->get('referer')) {
$message .= sprintf(' (from "%s")', $referer);
}
throw new NotFoundHttpException($message, $e);
} catch (MethodNotAllowedException $e) {
$message = sprintf('No route found for "%s %s": Method Not Allowed (Allow: %s)', $request->getMethod(), $request->getPathInfo(), implode(', ', $e->getAllowedMethods()));
throw new MethodNotAllowedHttpException($e->getAllowedMethods(), $message, $e);
}
}
public static function getSubscribedEvents()
{
return array(
KernelEvents::REQUEST => array(array('onKernelRequest', 32)),
KernelEvents::FINISH_REQUEST => array(array('onKernelFinishRequest', 0)),
);
}
}
}
namespace Symfony\Component\HttpKernel\Controller
{
use Symfony\Component\HttpFoundation\Request;
interface ControllerResolverInterface
{
public function getController(Request $request);
public function getArguments(Request $request, $controller);
}
}
namespace Symfony\Component\HttpKernel\Controller
{
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
class ControllerResolver implements ControllerResolverInterface
{
private $logger;
public function __construct(LoggerInterface $logger = null)
{
$this->logger = $logger;
}
public function getController(Request $request)
{
if (!$controller = $request->attributes->get('_controller')) {
if (null !== $this->logger) {
$this->logger->warning('Unable to look for the controller as the "_controller" parameter is missing.');
}
return false;
}
if (is_array($controller)) {
return $controller;
}
if (is_object($controller)) {
if (method_exists($controller,'__invoke')) {
return $controller;
}
throw new \InvalidArgumentException(sprintf('Controller "%s" for URI "%s" is not callable.', get_class($controller), $request->getPathInfo()));
}
if (false === strpos($controller,':')) {
if (method_exists($controller,'__invoke')) {
return $this->instantiateController($controller);
} elseif (function_exists($controller)) {
return $controller;
}
}
$callable = $this->createController($controller);
if (!is_callable($callable)) {
throw new \InvalidArgumentException(sprintf('The controller for URI "%s" is not callable. %s', $request->getPathInfo(), $this->getControllerError($callable)));
}
return $callable;
}
public function getArguments(Request $request, $controller)
{
if (is_array($controller)) {
$r = new \ReflectionMethod($controller[0], $controller[1]);
} elseif (is_object($controller) && !$controller instanceof \Closure) {
$r = new \ReflectionObject($controller);
$r = $r->getMethod('__invoke');
} else {
$r = new \ReflectionFunction($controller);
}
return $this->doGetArguments($request, $controller, $r->getParameters());
}
protected function doGetArguments(Request $request, $controller, array $parameters)
{
$attributes = $request->attributes->all();
$arguments = array();
foreach ($parameters as $param) {
if (array_key_exists($param->name, $attributes)) {
$arguments[] = $attributes[$param->name];
} elseif ($param->getClass() && $param->getClass()->isInstance($request)) {
$arguments[] = $request;
} elseif ($param->isDefaultValueAvailable()) {
$arguments[] = $param->getDefaultValue();
} else {
if (is_array($controller)) {
$repr = sprintf('%s::%s()', get_class($controller[0]), $controller[1]);
} elseif (is_object($controller)) {
$repr = get_class($controller);
} else {
$repr = $controller;
}
throw new \RuntimeException(sprintf('Controller "%s" requires that you provide a value for the "$%s" argument (because there is no default value or because there is a non optional argument after this one).', $repr, $param->name));
}
}
return $arguments;
}
protected function createController($controller)
{
if (false === strpos($controller,'::')) {
throw new \InvalidArgumentException(sprintf('Unable to find controller "%s".', $controller));
}
list($class, $method) = explode('::', $controller, 2);
if (!class_exists($class)) {
throw new \InvalidArgumentException(sprintf('Class "%s" does not exist.', $class));
}
return array($this->instantiateController($class), $method);
}
protected function instantiateController($class)
{
return new $class();
}
private function getControllerError($callable)
{
if (is_string($callable)) {
if (false !== strpos($callable,'::')) {
$callable = explode('::', $callable);
}
if (class_exists($callable) && !method_exists($callable,'__invoke')) {
return sprintf('Class "%s" does not have a method "__invoke".', $callable);
}
if (!function_exists($callable)) {
return sprintf('Function "%s" does not exist.', $callable);
}
}
if (!is_array($callable)) {
return sprintf('Invalid type for controller given, expected string or array, got "%s".', gettype($callable));
}
if (2 !== count($callable)) {
return sprintf('Invalid format for controller, expected array(controller, method) or controller::method.');
}
list($controller, $method) = $callable;
if (is_string($controller) && !class_exists($controller)) {
return sprintf('Class "%s" does not exist.', $controller);
}
$className = is_object($controller) ? get_class($controller) : $controller;
if (method_exists($controller, $method)) {
return sprintf('Method "%s" on class "%s" should be public and non-abstract.', $method, $className);
}
$collection = get_class_methods($controller);
$alternatives = array();
foreach ($collection as $item) {
$lev = levenshtein($method, $item);
if ($lev <= strlen($method) / 3 || false !== strpos($item, $method)) {
$alternatives[] = $item;
}
}
asort($alternatives);
$message = sprintf('Expected method "%s" on class "%s"', $method, $className);
if (count($alternatives) > 0) {
$message .= sprintf(', did you mean "%s"?', implode('", "', $alternatives));
} else {
$message .= sprintf('. Available methods: "%s".', implode('", "', $collection));
}
return $message;
}
}
}
namespace Symfony\Component\HttpKernel\Event
{
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\Event;
class KernelEvent extends Event
{
private $kernel;
private $request;
private $requestType;
public function __construct(HttpKernelInterface $kernel, Request $request, $requestType)
{
$this->kernel = $kernel;
$this->request = $request;
$this->requestType = $requestType;
}
public function getKernel()
{
return $this->kernel;
}
public function getRequest()
{
return $this->request;
}
public function getRequestType()
{
return $this->requestType;
}
public function isMasterRequest()
{
return HttpKernelInterface::MASTER_REQUEST === $this->requestType;
}
}
}
namespace Symfony\Component\HttpKernel\Event
{
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Request;
class FilterControllerEvent extends KernelEvent
{
private $controller;
public function __construct(HttpKernelInterface $kernel, callable $controller, Request $request, $requestType)
{
parent::__construct($kernel, $request, $requestType);
$this->setController($controller);
}
public function getController()
{
return $this->controller;
}
public function setController(callable $controller)
{
$this->controller = $controller;
}
}
}
namespace Symfony\Component\HttpKernel\Event
{
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
class FilterResponseEvent extends KernelEvent
{
private $response;
public function __construct(HttpKernelInterface $kernel, Request $request, $requestType, Response $response)
{
parent::__construct($kernel, $request, $requestType);
$this->setResponse($response);
}
public function getResponse()
{
return $this->response;
}
public function setResponse(Response $response)
{
$this->response = $response;
}
}
}
namespace Symfony\Component\HttpKernel\Event
{
use Symfony\Component\HttpFoundation\Response;
class GetResponseEvent extends KernelEvent
{
private $response;
public function getResponse()
{
return $this->response;
}
public function setResponse(Response $response)
{
$this->response = $response;
$this->stopPropagation();
}
public function hasResponse()
{
return null !== $this->response;
}
}
}
namespace Symfony\Component\HttpKernel\Event
{
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Request;
class GetResponseForControllerResultEvent extends GetResponseEvent
{
private $controllerResult;
public function __construct(HttpKernelInterface $kernel, Request $request, $requestType, $controllerResult)
{
parent::__construct($kernel, $request, $requestType);
$this->controllerResult = $controllerResult;
}
public function getControllerResult()
{
return $this->controllerResult;
}
public function setControllerResult($controllerResult)
{
$this->controllerResult = $controllerResult;
}
}
}
namespace Symfony\Component\HttpKernel\Event
{
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Request;
class GetResponseForExceptionEvent extends GetResponseEvent
{
private $exception;
public function __construct(HttpKernelInterface $kernel, Request $request, $requestType, \Exception $e)
{
parent::__construct($kernel, $request, $requestType);
$this->setException($e);
}
public function getException()
{
return $this->exception;
}
public function setException(\Exception $exception)
{
$this->exception = $exception;
}
}
}
namespace Symfony\Component\HttpKernel
{
final class KernelEvents
{
const REQUEST ='kernel.request';
const EXCEPTION ='kernel.exception';
const VIEW ='kernel.view';
const CONTROLLER ='kernel.controller';
const RESPONSE ='kernel.response';
const TERMINATE ='kernel.terminate';
const FINISH_REQUEST ='kernel.finish_request';
}
}
namespace Symfony\Component\HttpKernel\Config
{
use Symfony\Component\Config\FileLocator as BaseFileLocator;
use Symfony\Component\HttpKernel\KernelInterface;
class FileLocator extends BaseFileLocator
{
private $kernel;
private $path;
public function __construct(KernelInterface $kernel, $path = null, array $paths = array())
{
$this->kernel = $kernel;
if (null !== $path) {
$this->path = $path;
$paths[] = $path;
}
parent::__construct($paths);
}
public function locate($file, $currentPath = null, $first = true)
{
if (isset($file[0]) &&'@'=== $file[0]) {
return $this->kernel->locateResource($file, $this->path, $first);
}
return parent::locate($file, $currentPath, $first);
}
}
}
namespace Symfony\Bundle\FrameworkBundle\Controller
{
use Symfony\Component\HttpKernel\KernelInterface;
class ControllerNameParser
{
protected $kernel;
public function __construct(KernelInterface $kernel)
{
$this->kernel = $kernel;
}
public function parse($controller)
{
$originalController = $controller;
if (3 !== count($parts = explode(':', $controller))) {
throw new \InvalidArgumentException(sprintf('The "%s" controller is not a valid "a:b:c" controller string.', $controller));
}
list($bundle, $controller, $action) = $parts;
$controller = str_replace('/','\\', $controller);
$bundles = array();
try {
$allBundles = $this->kernel->getBundle($bundle, false);
} catch (\InvalidArgumentException $e) {
$message = sprintf('The "%s" (from the _controller value "%s") does not exist or is not enabled in your kernel!',
$bundle,
$originalController
);
if ($alternative = $this->findAlternative($bundle)) {
$message .= sprintf(' Did you mean "%s:%s:%s"?', $alternative, $controller, $action);
}
throw new \InvalidArgumentException($message, 0, $e);
}
foreach ($allBundles as $b) {
$try = $b->getNamespace().'\\Controller\\'.$controller.'Controller';
if (class_exists($try)) {
return $try.'::'.$action.'Action';
}
$bundles[] = $b->getName();
$msg = sprintf('The _controller value "%s:%s:%s" maps to a "%s" class, but this class was not found. Create this class or check the spelling of the class and its namespace.', $bundle, $controller, $action, $try);
}
if (count($bundles) > 1) {
$msg = sprintf('Unable to find controller "%s:%s" in bundles %s.', $bundle, $controller, implode(', ', $bundles));
}
throw new \InvalidArgumentException($msg);
}
public function build($controller)
{
if (0 === preg_match('#^(.*?\\\\Controller\\\\(.+)Controller)::(.+)Action$#', $controller, $match)) {
throw new \InvalidArgumentException(sprintf('The "%s" controller is not a valid "class::method" string.', $controller));
}
$className = $match[1];
$controllerName = $match[2];
$actionName = $match[3];
foreach ($this->kernel->getBundles() as $name => $bundle) {
if (0 !== strpos($className, $bundle->getNamespace())) {
continue;
}
return sprintf('%s:%s:%s', $name, $controllerName, $actionName);
}
throw new \InvalidArgumentException(sprintf('Unable to find a bundle that defines controller "%s".', $controller));
}
private function findAlternative($nonExistentBundleName)
{
$bundleNames = array_map(function ($b) {
return $b->getName();
}, $this->kernel->getBundles());
$alternative = null;
$shortest = null;
foreach ($bundleNames as $bundleName) {
if (false !== strpos($bundleName, $nonExistentBundleName)) {
return $bundleName;
}
$lev = levenshtein($nonExistentBundleName, $bundleName);
if ($lev <= strlen($nonExistentBundleName) / 3 && ($alternative === null || $lev < $shortest)) {
$alternative = $bundleName;
}
}
return $alternative;
}
}
}
namespace Symfony\Bundle\FrameworkBundle\Controller
{
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Controller\ControllerResolver as BaseControllerResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
class ControllerResolver extends BaseControllerResolver
{
protected $container;
protected $parser;
public function __construct(ContainerInterface $container, ControllerNameParser $parser, LoggerInterface $logger = null)
{
$this->container = $container;
$this->parser = $parser;
parent::__construct($logger);
}
protected function createController($controller)
{
if (false === strpos($controller,'::')) {
$count = substr_count($controller,':');
if (2 == $count) {
$controller = $this->parser->parse($controller);
} elseif (1 == $count) {
list($service, $method) = explode(':', $controller, 2);
return array($this->container->get($service), $method);
} elseif ($this->container->has($controller) && method_exists($service = $this->container->get($controller),'__invoke')) {
return $service;
} else {
throw new \LogicException(sprintf('Unable to parse the controller name "%s".', $controller));
}
}
return parent::createController($controller);
}
protected function instantiateController($class)
{
$controller = parent::instantiateController($class);
if ($controller instanceof ContainerAwareInterface) {
$controller->setContainer($this->container);
}
return $controller;
}
}
}
namespace Doctrine\Common\Lexer
{
abstract class AbstractLexer
{
private $input;
private $tokens = array();
private $position = 0;
private $peek = 0;
public $lookahead;
public $token;
public function setInput($input)
{
$this->input = $input;
$this->tokens = array();
$this->reset();
$this->scan($input);
}
public function reset()
{
$this->lookahead = null;
$this->token = null;
$this->peek = 0;
$this->position = 0;
}
public function resetPeek()
{
$this->peek = 0;
}
public function resetPosition($position = 0)
{
$this->position = $position;
}
public function getInputUntilPosition($position)
{
return substr($this->input, 0, $position);
}
public function isNextToken($token)
{
return null !== $this->lookahead && $this->lookahead['type'] === $token;
}
public function isNextTokenAny(array $tokens)
{
return null !== $this->lookahead && in_array($this->lookahead['type'], $tokens, true);
}
public function moveNext()
{
$this->peek = 0;
$this->token = $this->lookahead;
$this->lookahead = (isset($this->tokens[$this->position]))
? $this->tokens[$this->position++] : null;
return $this->lookahead !== null;
}
public function skipUntil($type)
{
while ($this->lookahead !== null && $this->lookahead['type'] !== $type) {
$this->moveNext();
}
}
public function isA($value, $token)
{
return $this->getType($value) === $token;
}
public function peek()
{
if (isset($this->tokens[$this->position + $this->peek])) {
return $this->tokens[$this->position + $this->peek++];
} else {
return null;
}
}
public function glimpse()
{
$peek = $this->peek();
$this->peek = 0;
return $peek;
}
protected function scan($input)
{
static $regex;
if ( ! isset($regex)) {
$regex = sprintf('/(%s)|%s/%s',
implode(')|(', $this->getCatchablePatterns()),
implode('|', $this->getNonCatchablePatterns()),
$this->getModifiers()
);
}
$flags = PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE;
$matches = preg_split($regex, $input, -1, $flags);
foreach ($matches as $match) {
$type = $this->getType($match[0]);
$this->tokens[] = array('value'=> $match[0],'type'=> $type,'position'=> $match[1],
);
}
}
public function getLiteral($token)
{
$className = get_class($this);
$reflClass = new \ReflectionClass($className);
$constants = $reflClass->getConstants();
foreach ($constants as $name => $value) {
if ($value === $token) {
return $className .'::'. $name;
}
}
return $token;
}
protected function getModifiers()
{
return'i';
}
abstract protected function getCatchablePatterns();
abstract protected function getNonCatchablePatterns();
abstract protected function getType(&$value);
}
}
namespace Doctrine\Common\Annotations
{
use Doctrine\Common\Lexer\AbstractLexer;
final class DocLexer extends AbstractLexer
{
const T_NONE = 1;
const T_INTEGER = 2;
const T_STRING = 3;
const T_FLOAT = 4;
const T_IDENTIFIER = 100;
const T_AT = 101;
const T_CLOSE_CURLY_BRACES = 102;
const T_CLOSE_PARENTHESIS = 103;
const T_COMMA = 104;
const T_EQUALS = 105;
const T_FALSE = 106;
const T_NAMESPACE_SEPARATOR = 107;
const T_OPEN_CURLY_BRACES = 108;
const T_OPEN_PARENTHESIS = 109;
const T_TRUE = 110;
const T_NULL = 111;
const T_COLON = 112;
protected $noCase = array('@'=> self::T_AT,','=> self::T_COMMA,'('=> self::T_OPEN_PARENTHESIS,')'=> self::T_CLOSE_PARENTHESIS,'{'=> self::T_OPEN_CURLY_BRACES,'}'=> self::T_CLOSE_CURLY_BRACES,'='=> self::T_EQUALS,':'=> self::T_COLON,'\\'=> self::T_NAMESPACE_SEPARATOR
);
protected $withCase = array('true'=> self::T_TRUE,'false'=> self::T_FALSE,'null'=> self::T_NULL
);
protected function getCatchablePatterns()
{
return array('[a-z_\\\][a-z0-9_\:\\\]*[a-z_][a-z0-9_]*','(?:[+-]?[0-9]+(?:[\.][0-9]+)*)(?:[eE][+-]?[0-9]+)?','"(?:""|[^"])*+"',
);
}
protected function getNonCatchablePatterns()
{
return array('\s+','\*+','(.)');
}
protected function getType(&$value)
{
$type = self::T_NONE;
if ($value[0] ==='"') {
$value = str_replace('""','"', substr($value, 1, strlen($value) - 2));
return self::T_STRING;
}
if (isset($this->noCase[$value])) {
return $this->noCase[$value];
}
if ($value[0] ==='_'|| $value[0] ==='\\'|| ctype_alpha($value[0])) {
return self::T_IDENTIFIER;
}
$lowerValue = strtolower($value);
if (isset($this->withCase[$lowerValue])) {
return $this->withCase[$lowerValue];
}
if (is_numeric($value)) {
return (strpos($value,'.') !== false || stripos($value,'e') !== false)
? self::T_FLOAT : self::T_INTEGER;
}
return $type;
}
}
}
namespace Doctrine\Common\Annotations
{
interface Reader
{
function getClassAnnotations(\ReflectionClass $class);
function getClassAnnotation(\ReflectionClass $class, $annotationName);
function getMethodAnnotations(\ReflectionMethod $method);
function getMethodAnnotation(\ReflectionMethod $method, $annotationName);
function getPropertyAnnotations(\ReflectionProperty $property);
function getPropertyAnnotation(\ReflectionProperty $property, $annotationName);
}
}
namespace Doctrine\Common\Annotations
{
class FileCacheReader implements Reader
{
private $reader;
private $dir;
private $debug;
private $loadedAnnotations = array();
private $classNameHashes = array();
private $umask;
public function __construct(Reader $reader, $cacheDir, $debug = false, $umask = 0002)
{
if ( ! is_int($umask)) {
throw new \InvalidArgumentException(sprintf('The parameter umask must be an integer, was: %s',
gettype($umask)
));
}
$this->reader = $reader;
$this->umask = $umask;
if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0777 & (~$this->umask), true)) {
throw new \InvalidArgumentException(sprintf('The directory "%s" does not exist and could not be created.', $cacheDir));
}
$this->dir = rtrim($cacheDir,'\\/');
$this->debug = $debug;
}
public function getClassAnnotations(\ReflectionClass $class)
{
if ( ! isset($this->classNameHashes[$class->name])) {
$this->classNameHashes[$class->name] = sha1($class->name);
}
$key = $this->classNameHashes[$class->name];
if (isset($this->loadedAnnotations[$key])) {
return $this->loadedAnnotations[$key];
}
$path = $this->dir.'/'.strtr($key,'\\','-').'.cache.php';
if (!is_file($path)) {
$annot = $this->reader->getClassAnnotations($class);
$this->saveCacheFile($path, $annot);
return $this->loadedAnnotations[$key] = $annot;
}
if ($this->debug
&& (false !== $filename = $class->getFilename())
&& filemtime($path) < filemtime($filename)) {
@unlink($path);
$annot = $this->reader->getClassAnnotations($class);
$this->saveCacheFile($path, $annot);
return $this->loadedAnnotations[$key] = $annot;
}
return $this->loadedAnnotations[$key] = include $path;
}
public function getPropertyAnnotations(\ReflectionProperty $property)
{
$class = $property->getDeclaringClass();
if ( ! isset($this->classNameHashes[$class->name])) {
$this->classNameHashes[$class->name] = sha1($class->name);
}
$key = $this->classNameHashes[$class->name].'$'.$property->getName();
if (isset($this->loadedAnnotations[$key])) {
return $this->loadedAnnotations[$key];
}
$path = $this->dir.'/'.strtr($key,'\\','-').'.cache.php';
if (!is_file($path)) {
$annot = $this->reader->getPropertyAnnotations($property);
$this->saveCacheFile($path, $annot);
return $this->loadedAnnotations[$key] = $annot;
}
if ($this->debug
&& (false !== $filename = $class->getFilename())
&& filemtime($path) < filemtime($filename)) {
@unlink($path);
$annot = $this->reader->getPropertyAnnotations($property);
$this->saveCacheFile($path, $annot);
return $this->loadedAnnotations[$key] = $annot;
}
return $this->loadedAnnotations[$key] = include $path;
}
public function getMethodAnnotations(\ReflectionMethod $method)
{
$class = $method->getDeclaringClass();
if ( ! isset($this->classNameHashes[$class->name])) {
$this->classNameHashes[$class->name] = sha1($class->name);
}
$key = $this->classNameHashes[$class->name].'#'.$method->getName();
if (isset($this->loadedAnnotations[$key])) {
return $this->loadedAnnotations[$key];
}
$path = $this->dir.'/'.strtr($key,'\\','-').'.cache.php';
if (!is_file($path)) {
$annot = $this->reader->getMethodAnnotations($method);
$this->saveCacheFile($path, $annot);
return $this->loadedAnnotations[$key] = $annot;
}
if ($this->debug
&& (false !== $filename = $class->getFilename())
&& filemtime($path) < filemtime($filename)) {
@unlink($path);
$annot = $this->reader->getMethodAnnotations($method);
$this->saveCacheFile($path, $annot);
return $this->loadedAnnotations[$key] = $annot;
}
return $this->loadedAnnotations[$key] = include $path;
}
private function saveCacheFile($path, $data)
{
if (!is_writable($this->dir)) {
throw new \InvalidArgumentException(sprintf('The directory "%s" is not writable. Both, the webserver and the console user need access. You can manage access rights for multiple users with "chmod +a". If your system does not support this, check out the acl package.', $this->dir));
}
$tempfile = tempnam($this->dir, uniqid('', true));
if (false === $tempfile) {
throw new \RuntimeException(sprintf('Unable to create tempfile in directory: %s', $this->dir));
}
$written = file_put_contents($tempfile,'<?php return unserialize('.var_export(serialize($data), true).');');
if (false === $written) {
throw new \RuntimeException(sprintf('Unable to write cached file to: %s', $tempfile));
}
@chmod($tempfile, 0666 & (~$this->umask));
if (false === rename($tempfile, $path)) {
@unlink($tempfile);
throw new \RuntimeException(sprintf('Unable to rename %s to %s', $tempfile, $path));
}
}
public function getClassAnnotation(\ReflectionClass $class, $annotationName)
{
$annotations = $this->getClassAnnotations($class);
foreach ($annotations as $annotation) {
if ($annotation instanceof $annotationName) {
return $annotation;
}
}
return null;
}
public function getMethodAnnotation(\ReflectionMethod $method, $annotationName)
{
$annotations = $this->getMethodAnnotations($method);
foreach ($annotations as $annotation) {
if ($annotation instanceof $annotationName) {
return $annotation;
}
}
return null;
}
public function getPropertyAnnotation(\ReflectionProperty $property, $annotationName)
{
$annotations = $this->getPropertyAnnotations($property);
foreach ($annotations as $annotation) {
if ($annotation instanceof $annotationName) {
return $annotation;
}
}
return null;
}
public function clearLoadedAnnotations()
{
$this->loadedAnnotations = array();
}
}
}
namespace Doctrine\Common\Annotations
{
use SplFileObject;
final class PhpParser
{
public function parseClass(\ReflectionClass $class)
{
if (method_exists($class,'getUseStatements')) {
return $class->getUseStatements();
}
if (false === $filename = $class->getFilename()) {
return array();
}
$content = $this->getFileContent($filename, $class->getStartLine());
if (null === $content) {
return array();
}
$namespace = preg_quote($class->getNamespaceName());
$content = preg_replace('/^.*?(\bnamespace\s+'. $namespace .'\s*[;{].*)$/s','\\1', $content);
$tokenizer = new TokenParser('<?php '. $content);
$statements = $tokenizer->parseUseStatements($class->getNamespaceName());
return $statements;
}
private function getFileContent($filename, $lineNumber)
{
if ( ! is_file($filename)) {
return null;
}
$content ='';
$lineCnt = 0;
$file = new SplFileObject($filename);
while (!$file->eof()) {
if ($lineCnt++ == $lineNumber) {
break;
}
$content .= $file->fgets();
}
return $content;
}
}
}
namespace Doctrine\Common
{
use Doctrine\Common\Lexer\AbstractLexer;
abstract class Lexer extends AbstractLexer
{
}
}
namespace Doctrine\Common\Persistence
{
interface ConnectionRegistry
{
public function getDefaultConnectionName();
public function getConnection($name = null);
public function getConnections();
public function getConnectionNames();
}
}
namespace Doctrine\Common\Persistence
{
interface Proxy
{
const MARKER ='__CG__';
const MARKER_LENGTH = 6;
public function __load();
public function __isInitialized();
}
}
namespace Doctrine\Common\Util
{
use Doctrine\Common\Persistence\Proxy;
class ClassUtils
{
public static function getRealClass($class)
{
if (false === $pos = strrpos($class,'\\'.Proxy::MARKER.'\\')) {
return $class;
}
return substr($class, $pos + Proxy::MARKER_LENGTH + 2);
}
public static function getClass($object)
{
return self::getRealClass(get_class($object));
}
public static function getParentClass($className)
{
return get_parent_class( self::getRealClass( $className ) );
}
public static function newReflectionClass($class)
{
return new \ReflectionClass( self::getRealClass( $class ) );
}
public static function newReflectionObject($object)
{
return self::newReflectionClass( self::getClass( $object ) );
}
public static function generateProxyClassName($className, $proxyNamespace)
{
return rtrim($proxyNamespace,'\\') .'\\'.Proxy::MARKER.'\\'. ltrim($className,'\\');
}
}
}
namespace Symfony\Component\DependencyInjection
{
interface ContainerAwareInterface
{
public function setContainer(ContainerInterface $container = null);
}
}
namespace Doctrine\Common\Persistence
{
interface ManagerRegistry extends ConnectionRegistry
{
public function getDefaultManagerName();
public function getManager($name = null);
public function getManagers();
public function resetManager($name = null);
public function getAliasNamespace($alias);
public function getManagerNames();
public function getRepository($persistentObject, $persistentManagerName = null);
public function getManagerForClass($class);
}
}
namespace Symfony\Bridge\Doctrine
{
use Doctrine\Common\Persistence\ManagerRegistry as ManagerRegistryInterface;
use Doctrine\ORM\EntityManager;
interface RegistryInterface extends ManagerRegistryInterface
{
public function getDefaultEntityManagerName();
public function getEntityManager($name = null);
public function getEntityManagers();
public function resetEntityManager($name = null);
public function getEntityNamespace($alias);
public function getEntityManagerNames();
public function getEntityManagerForClass($class);
}
}
namespace Symfony\Component\DependencyInjection
{
trait ContainerAwareTrait
{
protected $container;
public function setContainer(ContainerInterface $container = null)
{
$this->container = $container;
}
}
}
namespace Doctrine\Common\Persistence
{
abstract class AbstractManagerRegistry implements ManagerRegistry
{
private $name;
private $connections;
private $managers;
private $defaultConnection;
private $defaultManager;
private $proxyInterfaceName;
public function __construct($name, array $connections, array $managers, $defaultConnection, $defaultManager, $proxyInterfaceName)
{
$this->name = $name;
$this->connections = $connections;
$this->managers = $managers;
$this->defaultConnection = $defaultConnection;
$this->defaultManager = $defaultManager;
$this->proxyInterfaceName = $proxyInterfaceName;
}
abstract protected function getService($name);
abstract protected function resetService($name);
public function getName()
{
return $this->name;
}
public function getConnection($name = null)
{
if (null === $name) {
$name = $this->defaultConnection;
}
if (!isset($this->connections[$name])) {
throw new \InvalidArgumentException(sprintf('Doctrine %s Connection named "%s" does not exist.', $this->name, $name));
}
return $this->getService($this->connections[$name]);
}
public function getConnectionNames()
{
return $this->connections;
}
public function getConnections()
{
$connections = [];
foreach ($this->connections as $name => $id) {
$connections[$name] = $this->getService($id);
}
return $connections;
}
public function getDefaultConnectionName()
{
return $this->defaultConnection;
}
public function getDefaultManagerName()
{
return $this->defaultManager;
}
public function getManager($name = null)
{
if (null === $name) {
$name = $this->defaultManager;
}
if (!isset($this->managers[$name])) {
throw new \InvalidArgumentException(sprintf('Doctrine %s Manager named "%s" does not exist.', $this->name, $name));
}
return $this->getService($this->managers[$name]);
}
public function getManagerForClass($class)
{
if (strpos($class,':') !== false) {
list($namespaceAlias, $simpleClassName) = explode(':', $class, 2);
$class = $this->getAliasNamespace($namespaceAlias) .'\\'. $simpleClassName;
}
$proxyClass = new \ReflectionClass($class);
if ($proxyClass->implementsInterface($this->proxyInterfaceName)) {
if (! $parentClass = $proxyClass->getParentClass()) {
return null;
}
$class = $parentClass->getName();
}
foreach ($this->managers as $id) {
$manager = $this->getService($id);
if (!$manager->getMetadataFactory()->isTransient($class)) {
return $manager;
}
}
}
public function getManagerNames()
{
return $this->managers;
}
public function getManagers()
{
$dms = [];
foreach ($this->managers as $name => $id) {
$dms[$name] = $this->getService($id);
}
return $dms;
}
public function getRepository($persistentObjectName, $persistentManagerName = null)
{
return $this->getManager($persistentManagerName)->getRepository($persistentObjectName);
}
public function resetManager($name = null)
{
if (null === $name) {
$name = $this->defaultManager;
}
if (!isset($this->managers[$name])) {
throw new \InvalidArgumentException(sprintf('Doctrine %s Manager named "%s" does not exist.', $this->name, $name));
}
$this->resetService($this->managers[$name]);
}
}
}
namespace Symfony\Bridge\Doctrine
{
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Doctrine\Common\Persistence\AbstractManagerRegistry;
abstract class ManagerRegistry extends AbstractManagerRegistry implements ContainerAwareInterface
{
use ContainerAwareTrait;
protected function getService($name)
{
return $this->container->get($name);
}
protected function resetService($name)
{
$this->container->set($name, null);
}
}
}
namespace Doctrine\Bundle\DoctrineBundle
{
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Bridge\Doctrine\ManagerRegistry;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\EntityManager;
class Registry extends ManagerRegistry implements RegistryInterface
{
public function __construct(ContainerInterface $container, array $connections, array $entityManagers, $defaultConnection, $defaultEntityManager)
{
$this->setContainer($container);
parent::__construct('ORM', $connections, $entityManagers, $defaultConnection, $defaultEntityManager,'Doctrine\ORM\Proxy\Proxy');
}
public function getDefaultEntityManagerName()
{
trigger_error('getDefaultEntityManagerName is deprecated since Symfony 2.1. Use getDefaultManagerName instead', E_USER_DEPRECATED);
return $this->getDefaultManagerName();
}
public function getEntityManager($name = null)
{
trigger_error('getEntityManager is deprecated since Symfony 2.1. Use getManager instead', E_USER_DEPRECATED);
return $this->getManager($name);
}
public function getEntityManagers()
{
trigger_error('getEntityManagers is deprecated since Symfony 2.1. Use getManagers instead', E_USER_DEPRECATED);
return $this->getManagers();
}
public function resetEntityManager($name = null)
{
trigger_error('resetEntityManager is deprecated since Symfony 2.1. Use resetManager instead', E_USER_DEPRECATED);
$this->resetManager($name);
}
public function getEntityNamespace($alias)
{
trigger_error('getEntityNamespace is deprecated since Symfony 2.1. Use getAliasNamespace instead', E_USER_DEPRECATED);
return $this->getAliasNamespace($alias);
}
public function getAliasNamespace($alias)
{
foreach (array_keys($this->getManagers()) as $name) {
try {
return $this->getManager($name)->getConfiguration()->getEntityNamespace($alias);
} catch (ORMException $e) {
}
}
throw ORMException::unknownEntityNamespace($alias);
}
public function getEntityManagerNames()
{
trigger_error('getEntityManagerNames is deprecated since Symfony 2.1. Use getManagerNames instead', E_USER_DEPRECATED);
return $this->getManagerNames();
}
public function getEntityManagerForClass($class)
{
trigger_error('getEntityManagerForClass is deprecated since Symfony 2.1. Use getManagerForClass instead', E_USER_DEPRECATED);
return $this->getManagerForClass($class);
}
}
}