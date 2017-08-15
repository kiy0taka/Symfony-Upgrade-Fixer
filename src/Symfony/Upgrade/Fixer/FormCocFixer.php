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

class FormCocFixer extends FormTypeFixer
{

    public function fix(\SplFileInfo $file, $content)
    {
        $tokens = Tokens::fromCode($content);

        if (!$this->isFormType($tokens)) {
            return $content;
        }

        $classIndex = $tokens->getNextTokenOfKind(0, [[T_CLASS, 'class']]);
        $beforeClassIndex = $tokens->getPrevMeaningfulToken($classIndex);
        $docIndex = $tokens->getNextTokenOfKind($beforeClassIndex, [[T_DOC_COMMENT]]);
        if ($docIndex && $docIndex < $classIndex) {
            $docToken = $tokens[$docIndex];
            $docBlock = new DocBlock($docToken->getContent());
            $annotationFound = false;
            foreach ($docBlock->getAnnotations() as $annotation) {
                $annContent = $annotation->getContent();
                if (preg_match('/@FormType/', $annContent)) {
                    $annotationFound = true;
                    break;
                }
            }
            if ($annotationFound === false) {
                $docLines = [];
                foreach ($docBlock->getLines() as $line) {
                    if ($line->isTheEnd()) {
                        $docLines[] = ' * @FormType'.PHP_EOL;
                    }
                    $docLines[] = $line->getContent();
                }
                $tokens[$docIndex]->setContent(implode($docLines));
                $this->addUseStatement($tokens, ['Eccube', 'Annotation', 'FormType']);
            }

        } else {
//            if ($tokens[$classIndex - 1]->getId() === T_WHITESPACE) {
//                $token = $tokens[$classIndex - 1];
//                $token->setContent($token->getContent()."/**\n * @FormType\n */\n");
//            } else {
                $tokens->insertAt($classIndex, [
                    new Token([T_DOC_COMMENT, "/**\n * @FormType\n */"]),
                    new Token([T_WHITESPACE, "\n"])
                ]);
//            }
            $this->addUseStatement($tokens, ['Eccube', 'Annotation', 'FormType']);
        }
        return $tokens->generateCode();
    }

    public function getDescription()
    {
        // TODO: Implement getDescription() method.
    }
}