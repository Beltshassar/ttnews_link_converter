<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'TT News Link Converter',
    'description' => 'CLI command converting legacy tt_news RTE link tags in tx_news_domain_model_news.bodytext to TYPO3 record-link syntax. Developed with AI assistance (Claude Code).',
    'category' => 'misc',
    'author' => 'Daniel Alexander Damm',
    'author_email' => 'dad@imh.dk',
    'author_company' => 'IMHlab',
    'state' => 'alpha',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
