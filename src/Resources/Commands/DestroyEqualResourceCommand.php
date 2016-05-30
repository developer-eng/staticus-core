<?php
namespace Staticus\Resources\Commands;

use League\Flysystem\FilesystemInterface;
use Staticus\Resources\ResourceDOInterface;

class DestroyEqualResourceCommand implements ResourceCommandInterface
{
    /**
     * @var ResourceDOInterface
     */
    protected $originResourceDO;
    /**
     * @var ResourceDOInterface
     */
    protected $suspectResourceDO;
    /**
     * @var FilesystemInterface
     */
    protected $filesystem;

    /**
     * @param ResourceDOInterface $originResourceDO
     * @param ResourceDOInterface $suspectResourceDO This resource will be deleted, if equal to $originResourceDO
     * @param FilesystemInterface $filesystem
     */
    public function __construct(ResourceDOInterface $originResourceDO, ResourceDOInterface $suspectResourceDO, FilesystemInterface $filesystem)
    {
        $this->originResourceDO = $originResourceDO;
        $this->suspectResourceDO = $suspectResourceDO;
        $this->filesystem = $filesystem;
    }

    /**
     * @return ResourceDOInterface
     */
    public function __invoke()
    {
        $originType = $this->originResourceDO->getType();
        $suspectType = $this->suspectResourceDO->getType();
        $originFilePath = $this->originResourceDO->getFilePath();
        $suspectFilePath = $this->suspectResourceDO->getFilePath();
        if ($originType === $suspectType
            && $this->filesystem->getSize($originFilePath) === $this->filesystem->getSize($suspectFilePath)
            && md5_file($originFilePath) === md5_file($suspectFilePath)
        ) {
            $command = new DestroyResourceCommand($this->suspectResourceDO, $this->filesystem);

            return $command(true);
        }

        return $this->originResourceDO;
    }
}