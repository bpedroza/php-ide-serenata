<?php

namespace PhpIntegrator\Indexer;

use PhpParser\Node;
use PhpParser\NodeTraverser;

use PhpParser\NodeVisitor\NameResolver;

/**
 * Node visitor that indexes the outline of a file, creating a list of structural elements (classes, interfaces, ...)
 * with their direct methods, properties, constants, and so on.
 */
class OutlineIndexingVisitor extends NameResolver
{
    /**
     * @var array
     */
    protected $structures = [];

    /**
     * @var array
     */
    protected $globalFunctions = [];

    /**
     * @var array
     */
    protected $globalConstants = [];

    /**
     * @var Node\Stmt\Class_|null
     */
    protected $currentStructure;

    /**
     * @inheritDoc
     */
    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Property) {
            $this->parseClassPropertyNode($node);
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            $this->parseClassMethodNode($node);
        } elseif ($node instanceof Node\Stmt\ClassConst) {
            $this->parseClassConstantNode($node);
        } elseif ($node instanceof Node\Stmt\Function_) {
            $this->parseFunctionNode($node);
        } elseif ($node instanceof Node\Stmt\Const_) {
            $this->parseConstantNode($node);
        } elseif ($node instanceof Node\Stmt\Class_) {
            if ($node->isAnonymous()) {
                // Ticket #45 - Skip PHP 7 anonymous classes.
                return NodeTraverser::DONT_TRAVERSE_CHILDREN;
            }

            $this->parseClassNode($node);
        } elseif ($node instanceof Node\Stmt\Interface_) {
            $this->parseInterfaceNode($node);
        } elseif ($node instanceof Node\Stmt\Trait_) {
            $this->parseTraitNode($node);
        } elseif ($node instanceof Node\Stmt\TraitUse) {
            $this->parseTraitUseNode($node);
        } else {
            // Resolve the names for other nodes as the nodes we need depend on them being resolved.
            parent::enterNode($node);
        }
    }

    /**
     * @param Node\Stmt\Class_ $node
     */
    protected function parseClassNode(Node\Stmt\Class_ $node)
    {
        parent::enterNode($node);

        $this->currentStructure = $node;

        $interfaces = [];

        foreach ($node->implements as $implementedName) {
            $interfaces[] = $this->fetchClassName($implementedName);
        }

        $this->structures[$node->namespacedName->toString()] = [
            'name'       => $node->name,
            'fqcn'       => $node->namespacedName->toString(),
            'type'       => 'class',
            'startLine'  => $node->getLine(),
            'endLine'    => $node->getAttribute('endLine'),
            'startPos'   => $node->getAttribute('startFilePos') ? $node->getAttribute('startFilePos') : null,
            'endPos'     => $node->getAttribute('endFilePos') ? $node->getAttribute('endFilePos') : null,
            'isAbstract' => $node->isAbstract(),
            'docComment' => $node->getDocComment() ? $node->getDocComment()->getText() : null,
            'parents'    => $node->extends ? [$this->fetchClassName($node->extends)] : [],
            'interfaces' => $interfaces,
            'traits'     => [],
            'methods'    => [],
            'properties' => [],
            'constants'  => []
        ];
    }

    /**
     * @param Node\Stmt\Interface_ $node
     */
    protected function parseInterfaceNode(Node\Stmt\Interface_ $node)
    {
        parent::enterNode($node);

        if (!isset($node->namespacedName)) {
            return;
        }

        $this->currentStructure = $node;

        $extendedInterfaces = [];

        foreach ($node->extends as $extends) {
            $extendedInterfaces[] = $this->fetchClassName($extends);
        }

        $this->structures[$node->namespacedName->toString()] = [
            'name'       => $node->name,
            'fqcn'       => $node->namespacedName->toString(),
            'type'       => 'interface',
            'startLine'  => $node->getLine(),
            'endLine'    => $node->getAttribute('endLine'),
            'startPos'   => $node->getAttribute('startFilePos') ? $node->getAttribute('startFilePos') : null,
            'endPos'     => $node->getAttribute('endFilePos') ? $node->getAttribute('endFilePos') : null,
            'parents'    => $extendedInterfaces,
            'docComment' => $node->getDocComment() ? $node->getDocComment()->getText() : null,
            'traits'     => [],
            'methods'    => [],
            'properties' => [],
            'constants'  => []
        ];
    }

    /**
     * @param Node\Stmt\Trait_ $node
     */
    protected function parseTraitNode(Node\Stmt\Trait_ $node)
    {
        parent::enterNode($node);

        if (!isset($node->namespacedName)) {
            return;
        }

        $this->currentStructure = $node;

        $this->structures[$node->namespacedName->toString()] = [
            'name'       => $node->name,
            'fqcn'       => $node->namespacedName->toString(),
            'type'       => 'trait',
            'startLine'  => $node->getLine(),
            'endLine'    => $node->getAttribute('endLine'),
            'startPos'   => $node->getAttribute('startFilePos') ? $node->getAttribute('startFilePos') : null,
            'endPos'     => $node->getAttribute('endFilePos') ? $node->getAttribute('endFilePos') : null,
            'docComment' => $node->getDocComment() ? $node->getDocComment()->getText() : null,
            'methods'    => [],
            'properties' => [],
            'constants'  => []
        ];
    }

    /**
     * @param Node\Stmt\TraitUse $node
     */
    protected function parseTraitUseNode(Node\Stmt\TraitUse $node)
    {
        parent::enterNode($node);

        foreach ($node->traits as $traitName) {
            $this->structures[$this->currentStructure->namespacedName->toString()]['traits'][] =
                $this->fetchClassName($traitName);
        }

        foreach ($node->adaptations as $adaptation) {
            if ($adaptation instanceof Node\Stmt\TraitUseAdaptation\Alias) {
                $this->structures[$this->currentStructure->namespacedName->toString()]['traitAliases'][] = [
                    'name'                       => $adaptation->method,
                    'alias'                      => $adaptation->newName,
                    'trait'                      => $adaptation->trait ? $this->fetchClassName($adaptation->trait) : null,
                    'isPublic'                   => ($adaptation->newModifier === 1),
                    'isPrivate'                  => ($adaptation->newModifier === 4),
                    'isProtected'                => ($adaptation->newModifier === 2),
                    'isInheritingAccessModifier' => ($adaptation->newModifier === null)
                ];
            } elseif ($adaptation instanceof Node\Stmt\TraitUseAdaptation\Precedence) {
                $this->structures[$this->currentStructure->namespacedName->toString()]['traitPrecedences'][] = [
                    'name'  => $adaptation->method,
                    'trait' => $this->fetchClassName($adaptation->trait)
                ];
            }
        }
    }

    /**
     * @param Node\Stmt\Property $node
     */
    protected function parseClassPropertyNode(Node\Stmt\Property $node)
    {
        foreach ($node->props as $property) {
            $this->structures[$this->currentStructure->namespacedName->toString()]['properties'][] = [
                'name'        => $property->name,
                'startLine'   => $node->getLine(),
                'endLine'     => $node->getAttribute('endLine'),
                'startPos'    => $node->getAttribute('startFilePos') ? $node->getAttribute('startFilePos') : null,
                'endPos'      => $node->getAttribute('endFilePos') ? $node->getAttribute('endFilePos') : null,
                'isPublic'    => $node->isPublic(),
                'isPrivate'   => $node->isPrivate(),
                'isStatic'    => $node->isStatic(),
                'isProtected' => $node->isProtected(),
                'docComment'  => $node->getDocComment() ? $node->getDocComment()->getText() : null
            ];
        }
    }

    /**
     * @param Node\Stmt\Function_ $node
     */
    protected function parseFunctionNode(Node\Stmt\Function_ $node)
    {
        $parameters = [];

        foreach ($node->params as $param) {
            $localType = null;

            if ($param->type instanceof Node\Name) {
                $localType = $this->fetchClassName($param->type);
            } else {
                $localType = (string) $param->type;
            }

            parent::enterNode($param);

            $resolvedType = null;

            if ($param->type instanceof Node\Name) {
                $resolvedType = $this->fetchClassName($param->type);
            } else {
                $resolvedType = (string) $param->type;
            }

            $parameters[] = [
                'name'        => $param->name,
                'type'        => $localType,
                'fullType'    => $resolvedType,
                'isReference' => $param->byRef,
                'isVariadic'  => $param->variadic,
                'isOptional'  => $param->default ? true : false
            ];
        }

        $localType = null;
        $nodeType = $node->getReturnType();

        if ($nodeType instanceof Node\Name) {
            $localType = $this->fetchClassName($nodeType);
        } else {
            $localType = (string) $nodeType;
        }

        parent::enterNode($node);

        $resolvedType = null;
        $nodeType = $node->getReturnType();

        if ($nodeType instanceof Node\Name) {
            $resolvedType = $this->fetchClassName($nodeType);
        } else {
            $resolvedType = (string) $nodeType;
        }

        $this->globalFunctions[] = [
            'name'           => $node->name,
            'startLine'      => $node->getLine(),
            'endLine'        => $node->getAttribute('endLine'),
            'startPos'       => $node->getAttribute('startFilePos') ? $node->getAttribute('startFilePos') : null,
            'endPos'         => $node->getAttribute('endFilePos') ? $node->getAttribute('endFilePos') : null,
            'returnType'     => $localType,
            'fullReturnType' => $resolvedType,
            'parameters'     => $parameters,
            'docComment'     => $node->getDocComment() ? $node->getDocComment()->getText() : null
        ];
    }

    /**
     * @param Node\Stmt\ClassMethod $node
     */
    protected function parseClassMethodNode(Node\Stmt\ClassMethod $node)
    {
        $parameters = [];

        foreach ($node->params as $param) {
            $localType = null;

            if ($param->type instanceof Node\Name) {
                $localType = $this->fetchClassName($param->type);
            } else {
                $localType = (string) $param->type;
            }

            parent::enterNode($param);

            $resolvedType = null;

            if ($param->type instanceof Node\Name) {
                $resolvedType = $this->fetchClassName($param->type);
            } else {
                $resolvedType = (string) $param->type;
            }

            $parameters[] = [
                'name'        => $param->name,
                'type'        => $localType,
                'fullType'    => $resolvedType,
                'isReference' => $param->byRef,
                'isVariadic'  => $param->variadic,
                'isOptional'  => $param->default ? true : false
            ];
        }

        $localType = null;
        $nodeType = $node->getReturnType();

        if ($nodeType instanceof Node\Name) {
            $localType = $this->fetchClassName($nodeType);
        } else {
            $localType = (string) $nodeType;
        }

        parent::enterNode($node);

        $resolvedType = null;
        $nodeType = $node->getReturnType();

        if ($nodeType instanceof Node\Name) {
            $resolvedType = $this->fetchClassName($nodeType);
        } else {
            $resolvedType = (string) $nodeType;
        }

        $this->structures[$this->currentStructure->namespacedName->toString()]['methods'][] = [
            'name'           => $node->name,
            'startLine'      => $node->getLine(),
            'endLine'        => $node->getAttribute('endLine'),
            'startPos'       => $node->getAttribute('startFilePos') ? $node->getAttribute('startFilePos') : null,
            'endPos'         => $node->getAttribute('endFilePos') ? $node->getAttribute('endFilePos') : null,
            'isPublic'       => $node->isPublic(),
            'isPrivate'      => $node->isPrivate(),
            'isProtected'    => $node->isProtected(),
            'isAbstract'     => $node->isAbstract(),
            'isStatic'       => $node->isStatic(),
            'returnType'     => $localType,
            'fullReturnType' => $resolvedType,
            'parameters'     => $parameters,
            'docComment'     => $node->getDocComment() ? $node->getDocComment()->getText() : null
        ];
    }

    /**
     * @param Node\Stmt\ClassConst $node
     */
    protected function parseClassConstantNode(Node\Stmt\ClassConst $node)
    {
        foreach ($node->consts as $const) {
            $this->structures[$this->currentStructure->namespacedName->toString()]['constants'][] = [
                'name'       => $const->name,
                'startLine'  => $node->getLine(),
                'endLine'    => $node->getAttribute('endLine'),
                'startPos'   => $node->getAttribute('startFilePos') ? $node->getAttribute('startFilePos') : null,
                'endPos'     => $node->getAttribute('endFilePos') ? $node->getAttribute('endFilePos') : null,
                'docComment' => $node->getDocComment() ? $node->getDocComment()->getText() : null
            ];
        }
    }

    /**
     * @param Node\Stmt\Const_ $node
     */
    protected function parseConstantNode(Node\Stmt\Const_ $node)
    {
        foreach ($node->consts as $const) {
            $this->globalConstants[] = [
                'name'       => $const->name,
                'startLine'  => $node->getLine(),
                'endLine'    => $node->getAttribute('endLine'),
                'startPos'   => $node->getAttribute('startFilePos') ? $node->getAttribute('startFilePos') : null,
                'endPos'     => $node->getAttribute('endFilePos') ? $node->getAttribute('endFilePos') : null,
                'docComment' => $node->getDocComment() ? $node->getDocComment()->getText() : null
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function leaveNode(Node $node)
    {
        if ($this->currentStructure === $node) {
            $this->currentStructure = null;
        }
    }

    /**
     * Takes a class name and turns it into a string.
     *
     * @param Node\Name $name
     *
     * @return string
     */
    protected function fetchClassName(Node\Name $name)
    {
        $newName = (string) $name;

        if ($name->isFullyQualified() && $newName[0] !== '\\') {
            $newName = '\\' . $newName;
        }

        return $newName;
    }

    /**
     * Retrieves the list of structural elements.
     *
     * @return array
     */
    public function getStructures()
    {
        return $this->structures;
    }

    /**
     * Retrieves the list of (global) functions.
     *
     * @return array
     */
    public function getGlobalFunctions()
    {
        return $this->globalFunctions;
    }

    /**
     * Retrieves the list of (global) constants.
     *
     * @return array
     */
    public function getGlobalConstants()
    {
        return $this->globalConstants;
    }
}
