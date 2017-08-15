<?php
/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) 2000-2017 LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

namespace Symfony\Upgrade\Fixer;


use Symfony\CS\DocBlock\DocBlock;
use Symfony\CS\Tokenizer\Token;
use Symfony\CS\Tokenizer\Tokens;

class AppDestroyerFixer extends AbstractFixer
{
    private $pimpleJson;

    const IGNORE_PREFIX = ['eccube.purchase.flow.'];

    public function __construct()
    {
        $this->pimpleJson = array_reduce(json_decode(file_get_contents(__DIR__.'/../../../pimple.json'), true), function($acc, $val) {
            $acc[$val['name']] = $val;
            return $acc;
        }, []);
        echo __DIR__.PHP_EOL;
    }

    /**
     * Fixes a file.
     *
     * @param \SplFileInfo $file A \SplFileInfo instance
     * @param string $content The file content
     *
     * @return string The fixed file content
     */
    public function fix(\SplFileInfo $file, $content)
    {
        $tokens = Tokens::fromCode($content);
        $this->replaceGetRepository($tokens);
        $this->replaceApp($tokens);

        $afterContent = $tokens->generateCode();
        if ($content != $afterContent) {
            $this->addUseStatement($tokens, ['Eccube', 'Annotation', 'Inject']);
        }
        return $tokens->generateCode();
    }

    private function replaceApp(Tokens $tokens)
    {
        $currentIndex = 0;
        do {
            $found = $tokens->findSequence([
                [T_VARIABLE, '$app'],
                '[',
                [T_CONSTANT_ENCAPSED_STRING],
                ']',
            ], $currentIndex);

            if ($found) {
                $indexes = array_keys($found);
                $componentKey = preg_replace('/[\'"]/', '', $found[$indexes[2]]->getContent());
                if (isset($this->pimpleJson[$componentKey])) {
                    $componentDef = $this->pimpleJson[$componentKey];

                    if ($componentDef['type'] === 'class') {
                        $componentFqcn = explode('\\', $componentDef['value']);
                        $className = end($componentFqcn);
                        $varName = lcfirst(str_replace('_', '', $className));
                        $tokens->clearRange($indexes[0], $indexes[3]);      // -4
                        $tokens->insertAt($indexes[0], [                    // +3
                            new Token([T_VARIABLE, '$this']),
                            new Token([T_OBJECT_OPERATOR, '->']),
                            new Token([T_STRING, $varName])
                        ]);
                        $offset = (- 4 + 3);
                        if ($this->addField($tokens, $this->isUseClassNameForComponentKey($componentKey) ? $className.'::class' : "\"$componentKey\"", $className, $varName)) {  // +8
                            $this->addUseStatement($tokens, $componentFqcn);    // +2
                            $offset += 8;
                            $offset += 2;
                        };
                        $currentIndex = $indexes[3] + $offset;
                    } else if ($componentKey === 'config') {
                        $tokens->clearRange($indexes[0], $indexes[3]);      // -4
                        $tokens->insertAt($indexes[0], [                    // +3
                            new Token([T_VARIABLE, '$this']),
                            new Token([T_OBJECT_OPERATOR, '->']),
                            new Token([T_STRING, 'appConfig'])
                        ]);
                        $offset = (- 4 + 3);
                        if ($this->addField($tokens, '"config"', 'array', 'appConfig')) {
                            $offset += 8;
                        }
                        $currentIndex = $indexes[3] + $offset;
                    } else {
                        $currentIndex = $indexes[3];
                    }
                } else {
                    $currentIndex = $indexes[3];
                }
            }

        } while ($found);
    }

    private function isUseClassNameForComponentKey($componentKey)
    {
        if (strpos($componentKey, 'eccube.') !== 0) {
            return false;
        }
        foreach (self::IGNORE_PREFIX as $prefix) {
            if (strpos($componentKey, $prefix) === 0) {
                return false;
            }
        }
        return true;
    }

    private function replaceGetRepository(Tokens $tokens)
    {
        $currentIndex = 0;
        do {
            $found = $tokens->findSequence([
                [T_VARIABLE, '$app'],
                '[',
                [T_CONSTANT_ENCAPSED_STRING, "'orm.em'"],
                ']',
                [T_OBJECT_OPERATOR],
                [T_STRING, 'getRepository'],
                '(',
                [T_CONSTANT_ENCAPSED_STRING],
                ')'
            ], $currentIndex);

            if ($found) {
                $indexes = array_keys($found);
                $entityFqcn = preg_replace('/[\'"]/', '', $found[$indexes[7]]->getContent());
                $entityName = explode('\\', $entityFqcn);
                $repositoryName = end($entityName) . 'Repository';
                $varName = lcfirst($repositoryName);

                $tokens->clearRange($indexes[0], $indexes[8]); // -9
                $tokens->insertAt($indexes[0], [ // +3
                    new Token([T_VARIABLE, '$this']),
                    new Token([T_OBJECT_OPERATOR, '->']),
                    new Token([T_STRING, $varName])
                ]);

                $this->addField($tokens, $repositoryName.'::class', $repositoryName, $varName); // +8
                $repositoryFqcn = str_replace('\\Entity\\', '\\Repository\\', $entityFqcn) . 'Repository';
                $this->addUseStatement($tokens, explode('\\', $repositoryFqcn)); // +2

                $currentIndex = $indexes[8] - 9 + 3 + 8 + 2;
            }

        } while ($found);
    }

    private function addField(Tokens $tokens, $componentKey, $className, $varName)
    {
        if ($this->findField($tokens, $componentKey)) {
            return false;
        }
        $classTokenIndex = $tokens->getNextTokenOfKind(0, [[T_CLASS]]);
        $classCurlyBracket = $tokens->getNextTokenOfKind($classTokenIndex, ['{']);
        $tokens->insertAt($classCurlyBracket + 1, [
            new Token([T_WHITESPACE, PHP_EOL.'    ']),
            new Token([T_DOC_COMMENT, "/**\n     * @Inject($componentKey)\n     * @var $className\n     */"]),
            new Token([T_WHITESPACE, PHP_EOL.'    ']),
            new Token([T_PROTECTED, 'protected']),
            new Token([T_WHITESPACE, ' ']),
            new Token([T_VARIABLE, '$'.$varName]),
            new Token(';'),
            new Token([T_WHITESPACE, PHP_EOL]),
        ]);

        if ($tokens[$classCurlyBracket + 9]->getId() === T_WHITESPACE) {
            $tokens[$classCurlyBracket + 8]->setContent(PHP_EOL . $tokens[$classCurlyBracket + 9]->getContent());
            $tokens[$classCurlyBracket + 9]->clear();
        }

        return true;
    }

    private function findField(Tokens $tokens, $componentKey)
    {
        foreach ([T_PRIVATE, T_PROTECTED, T_PUBLIC] as $accessibility) {
            $currentIndex = 0;
            do {
                $found = $tokens->findSequence([
                    [$accessibility],
                    [T_VARIABLE]
                ], $currentIndex);
                if ($found) {
                    list($fieldStart, $fieldEnd) = array_keys($found);
                    $prevMeaningfulTokenIndex = $tokens->getPrevMeaningfulToken($fieldStart);
                    $docCommentIndex = $tokens->getNextTokenOfKind($prevMeaningfulTokenIndex, [[T_DOC_COMMENT]]);
                    if ($docCommentIndex && $docCommentIndex < $fieldStart) {
                        $docContent = $tokens[$docCommentIndex]->getContent();
                        $dockBlock = new DocBlock($docContent);
                        foreach ($dockBlock->getAnnotations() as $annotation) {
                            $annContent = $annotation->getContent();
                            $matches = [];
                            preg_match_all('/@Inject\((.*)\)/', $annContent, $matches);
                            if (!empty($matches[1]) && $matches[1][0] === $componentKey) {
                                return true;
                            }
                        }
                    }
                    $currentIndex = $fieldEnd;
                }
            } while ($found);
        }
        return false;
    }

    /**
     * Returns the description of the fixer.
     *
     * A short one-line description of what the fixer does.
     *
     * @return string The description of the fixer
     */
    public function getDescription()
    {
        // TODO: Implement getDescription() method.
    }
}