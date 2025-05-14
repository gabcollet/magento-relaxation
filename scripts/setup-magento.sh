#!/bin/bash

bin/magento setup:install \
  --base-url="https://magento-relaxation.test/" \
  --db-host=db \
  --db-name=magento \
  --db-user=magento \
  --db-password=magento \
  --admin-firstname=Admin \
  --admin-lastname=Magento \
  --admin-email=admin@example.com \
  --admin-user=admin \
  --admin-password=Admin123! \
  --language=fr_FR \
  --currency=CAD \
  --timezone=America/Toronto \
  --use-rewrites=1 \
  --search-engine=opensearch \
  --opensearch-host=opensearch \
  --opensearch-port=9200 \
  --opensearch-index-prefix=magento2 \
  --opensearch-timeout=15

bin/magento deploy:mode:set developer
bin/magento cache:flush
bin/magento indexer:reindex
bin/magento setup:di:compile
