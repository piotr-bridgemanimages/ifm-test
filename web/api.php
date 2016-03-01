<?php

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\RouteCollectionBuilder;

// require Composer's autoloader
require __DIR__.'/../vendor/autoload.php';

\Doctrine\Common\Annotations\AnnotationRegistry::registerLoader('class_exists');

class AppKernel extends Kernel
{
	use MicroKernelTrait;

	public function registerBundles()
	{
		return array(
			new Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
			new Doctrine\Bundle\DoctrineBundle\DoctrineBundle(),
			new AppBundle\AppBundle(),
		);
	}

	protected function configureContainer(ContainerBuilder $c, LoaderInterface $loader)
	{
		$loader->load(__DIR__.'/../app/config/api.yml');
		// PHP equivalent of config.yml
//		$c->loadFromExtension('framework', array(
//			'secret' => 'S0ME_SECRET'
//		));
	}

	protected function configureRoutes(RouteCollectionBuilder $routes)
	{
		// kernel is a service that points to this class
		// optional 3rd argument is the route name
		$routes->add('/', 'kernel:testAction');
	}

	public function testAction()
	{
		$em = $this->getContainer()->get('doctrine')->getManager();
		$file = new \AppBundle\Entity\File();
		$file->setOriginalName('XXXX');
		$file->setPath('hehehe');
		$file->setLocal(true);
		$em->persist($file);
		$em->flush();
		return new JsonResponse(array('id' => $file->getId()));
	}
}

$kernel = new AppKernel('prod', false);
$kernel->loadClassCache();
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
