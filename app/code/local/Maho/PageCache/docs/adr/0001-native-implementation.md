# ADR-0001：使用 Maho 原生实现，不移植 RockSolid 或 Cm_Diehard

## 状态

已接受。

## 背景

RockSolid_PageCache 和 Cm_Diehard 都是 Magento 1 / OpenMage 时代的整页缓存模块，设计成熟，但依赖已经不在 Maho 里的 API：

- `Zend_Cache` 和 `Mage_Core_Model_Cache` 的旧前端 API
- `global/request_rewrite` 和 `Mage_Core_Model_Url_Rewrite_Request`
- 旧版 `Mage_Core_Model_Layout` 内部结构
- 运行时生成 `Mage_Catalog_Model_Product_Interceptor`
- `magento-hackathon/magento-composer-installer`

两者都是 GPL-3.0。Maho 使用 OSL-3.0/AFL-3.0。

## 决策

在 `app/code/local/Maho/PageCache` 新建 Maho 原生模块，只参考两个库的设计，不复制代码、不引入它们的依赖、不修改 vendor。

## 理由

- Maho 的缓存栈已经换成 Symfony Cache，旧模块无法直接运行。
- 重写比适配旧模块更可控，测试和文档可以按 Maho 的约定写。
- 许可证风险不能通过“只改一点”规避。
- Maho 的 session、路由、layout 和 block 都已经现代化，新模块可以直接使用新事件和新 API。

## 后果

- 需要自己实现 context、ETag、tag 索引、purge 队列和测试。
- 不能直接获得两个旧模块的成熟实现，需要更长的验证周期。
- 可以分三期交付，每期范围可控。

## 备选方案

- **直接移植 RockSolid**：依赖缺失、GPL、layout 冲突，不采用。
- **直接移植 Cm_Diehard**：依赖缺失、GPL、request_processors 不存在，不采用。
- **只做 Varnish 配置，不写模块**：没有 Maho 侧的 session skip、tag purge 和 context 管理，不采用。
