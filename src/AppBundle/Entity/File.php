<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * File
 * @ORM\Entity
 * @ORM\Table(name="files")
 **/
class File
{
	/**
	 * @var integer
	 * @ORM\Id
	 * @ORM\Column(type="integer")
	 * @ORM\GeneratedValue(strategy="AUTO")
	 */
	protected $id;

	/**
	 * @var string
	 *
	 * @ORM\Column(type="string", length=255, options={"fixed":true})
	 */
	protected $originalName = '';

	/**
	 * @var string
	 *
	 * @ORM\Column(type="string", length=255, options={"fixed":true})
	 */
	protected $path = '';

	/**
	 * @var boolean
	 *
	 * @ORM\Column(type="boolean")
	 */
	protected $local = '';

	/**
	 * @return int
	 */
	public function getId()
	{
		return $this->id;
	}

	/**
	 * @return string
	 */
	public function getOriginalName()
	{
		return $this->originalName;
	}

	/**
	 * @param string $originalName
	 * @return File
	 */
	public function setOriginalName($originalName)
	{
		$this->originalName = $originalName;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getPath()
	{
		return $this->path;
	}

	/**
	 * @param string $path
	 * @return File
	 */
	public function setPath($path)
	{
		$this->path = $path;
		return $this;
	}

	/**
	 * @return boolean
	 */
	public function isLocal()
	{
		return $this->local;
	}

	/**
	 * @param boolean $local
	 * @return File
	 */
	public function setLocal($local)
	{
		$this->local = $local;
		return $this;
	}
}
