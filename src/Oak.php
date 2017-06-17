<?php
namespace Methodin\Oak;

use Doctrine\Common\Annotations\Reader;
use Methodin\Oak\Annotations\{Branch,Bind};

class Oak
{
    private $reader;
    private $baseDir;
    
    private $graph = [];
    private $bindings = [];

    public function __construct(Reader $reader, $baseDir)
    {
        $this->reader = $reader; 
        $this->baseDir = realpath($baseDir).'/'; 
    }

    public function init()
    {
    }

    public function register($branch)
    {
        $reflObject = new \ReflectionObject($branch);
        $classAnnotations = $this->reader->getClassAnnotations($reflObject);

        $isOak = false;
        foreach ($classAnnotations as $annot) {
            if ($annot instanceof Branch) {
                $isOak = true;
                break;
            } 
        }

        if (!$isOak) {
            throw new \InvalidArgumentException('Class provided must be annotated with the Branch annotation');
        }

        foreach ($reflObject->getMethods() as $method) {
            $annotations = $this->reader->getMethodAnnotations($method);
            foreach ($annotations as $annot) {
                if ($annot instanceof Bind) {
                    $events = $annot->events;

                    if (!is_array($annot->events)) {
                        $events = [$annot->events];
                    } 

                    $fileName = str_replace($this->baseDir, '', $method->getFileName());

                    $usedEvents = $this->getEmittedEvents($method);
                    $diff = array_diff($usedEvents, $annot->emits);

                    if ($diff) {
                        throw new \LogicException(sprintf(
                            'The file %s:%s is emitting the following events not declared in annotation: %s',
                            $fileName,
                            $method->getName(),
                            implode(', ', $diff)
                        ));
                    }

                    $diff = array_diff($annot->emits, $usedEvents);

                    if ($diff) {
                        throw new \LogicException(sprintf(
                            'The file %s:%s states it emits the following events it does not emit: %s',
                            $fileName,
                            $method->getName(),
                            implode(', ', $diff)
                        ));
                    }
                    
                    foreach ($events as $event) {
                        $this->bind($event, [$branch, $method->getName()]);
                        $this->graph(
                            $event,
                            get_class($branch),
                            $method->getName(),
                            $annot->emits,
                            $fileName
                        );
                    }
                }
            }
        }
    }

    public function getGraph()
    {
        return $this->graph;
    }

    public function getDependencyMap()
    {
        $map = [];

        $events = ['app.init'];
        while ($events) {
            $event = array_shift($events);

            $node = $this->getGraphNode($event);
            $name = $node['class'].'.'.$node['method'];

            foreach ($node['emissions'] as $dependentEvent) {
                $dependentNode = $this->getGraphNode($dependentEvent);
                $dependentName = $dependentNode['class'].'.'.$dependentNode['method'];
                $map[$name]['down'][] = $dependentName;
                $map[$dependentName]['up'][] = $name;
                $events[] = $dependentEvent;
            }
        }

        return $map;
    }

    public function getAffectedMethods($name)
    {
        $map = $this->getDependencyMap();

        if (!isset($map[$name])) {
            throw new \Exception(sprintf('Unknown class/method found %s', $name));
        }

        $affected = [];
        $list = $map[$name]['down'];

        while ($list) {
            $affectedName = array_pop($list);
            $affected[] = $affectedName;

            if (!isset($map[$affectedName]['down'])) {
                continue;
            }

            $list = array_merge(
                $list,
                $map[$affectedName]['down']
            );
        }

        return $affected;
    }


    public function emit($event, ...$params)
    {
        if (!isset($this->graph[$event])) {
            throw new \Exception(sprintf(
                'Event %s not found',
                $event
            ));
        }

        $this->bindings[$event]($this, ...$params);
    }

    private function getEmittedEvents(\ReflectionMethod $method)
    {
        $filename = $method->getFileName();
        $start_line = $method->getStartLine() - 1;
        $end_line = $method->getEndLine();
        $length = $end_line - $start_line;

        $source = file($filename);
        $body = implode("", array_slice($source, $start_line, $length));

        if (!preg_match_all('#emit\([\'"]([^\'"]+)[\'"]#', $body, $matches)) {
            return [];
        }

        return $matches[1];
    }

    private function bind(string $event, callable $callable)
    {
        if (isset($this->bindings[$event])) {
            throw new \InvalidArgumentException(sprintf(
                "The event %s is already bound",
                $event
            ));
        }

        $this->bindings[$event] = $callable;
    }

    private function graph(string $parent, string $class, string $method, array $children, string $fileName)
    {
        if (!isset($this->graph[$parent])) {
            $this->graph[$parent] = [
                'class' => $class,
                'method' => $method,
                'file' => $fileName,
                'emissions' => []
            ];
        }

        $this->graph[$parent]['emissions'] = array_merge(
            $this->graph[$parent]['emissions'],
            $children
        );
    }

    private function getGraphNode(string $event)
    {
        if (!isset($this->graph[$event])) {
            throw new \Exception(sprintf(
                'Dangling event %s found - contains no handlers',
                $event
            ));
        }

        return $this->graph[$event];
    }
}
