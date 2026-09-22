# Покрытие конфигурационных ключей X-DIAG

Автоматический аудит локальной таблицы URL. Неизвестные команды возвращают локальную ошибку.
SOAP-методы: getRegisteredProductsForPad; queryLatestDiagSofts и поддержанные Incr/Base-варианты; queryHistoryDiagSofts; queryLatestPublicSofts.

| Ключ APK | Локальный path | Обработчик |
|---|---|---|
| config.urls | `/?action=config_service.urls` | Локальный JSON |
| user.get_base_info | `/?action=userinfo.get_base_info` | Локальный JSON |
| user.get_base_info_car_logo | `/?action=userinfo.get_base_info_car_logo` | Не реализован |
| user.set_base | `/?action=userinfo.set_base` | Не реализован |
| user.set_area | `/?action=userinfo.set_area` | Не реализован |
| user.unbind_tel | `/?action=userinfo.unbind_tel` | Не реализован |
| user.unbind_email | `/?action=userinfo.unbind_email` | Не реализован |
| user.get_contact | `/?action=userinfo.get_contact` | Не реализован |
| user.set_ext | `/?action=userinfo.set_ext` | Не реализован |
| user.get_priconf | `/?action=userinfo.get_priconf` | Не реализован |
| user.set_conf | `/?action=userinfo.set_conf` | Не реализован |
| user.get_common | `/?action=userinfo.get_common` | Не реализован |
| user.get_rand_hobby | `/?action=userinfo.get_rand_hobby` | Не реализован |
| user.get_hobby | `/?action=userinfo.get_hobby` | Не реализован |
| user.get_map_conf | `/?action=userinfo.get_map_conf` | Не реализован |
| userinfo.set_password | `/?action= userinfo.set_password` | Не реализован |
| verify.req_send_code | `/?action=verifycode.req_send_code` | Не реализован |
| verify.request | `/?action=verifycode.request_send_code` | Не реализован |
| verify.verify_code | `/?action=verifycode.verify` | Не реализован |
| verify.reset_pass | `/?action=passport_service.reset_pass` | Не реализован |
| diagsoft.download | `/mobile/softCenter/downloadEncryptDiagSoft.action` | PHP download с ACL |
| downloaddiagsoftws.action | `/mobile/softCenter/downloadDiagSoftWs.action` | PHP download с ACL |
| area.get_country_list | `/?action=area_service.get_country_list` | Не реализован |
| area.get_country | `/?action=area_service.get_country` | Не реализован |
| area.get_province | `/?action=area_service.get_province` | Не реализован |
| area.get_city | `/?action=area_service.get_city` | Не реализован |
| lbs.get_nearby | `/?action=lbs_service.get_nearby` | Не реализован |
| lbs.get_nearby_repairshop | `/?action=lbs_service.get_nearby_repairshop` | Не реализован |
| lbs.get_nearby_public | `/?action=lbs_service.get_nearby_public` | Не реализован |
| lbs.clear_location | `/?action=lbs_service.clear_location` | Не реализован |
| lbs.update_location | `/?action=lbs_service.update_location` | Не реализован |
| login | `/?action=passport_service.login` | Локальный JSON |
| register | `/?action=passport_service.register` | Локальный JSON |
| reg_user | `/?action=passport_service.reg_user` | Локальный JSON |
| logout | `/?action=passport_service.logout` | Локальный JSON |
| feedback.post | `/?action=feedback.post_feedback` | Не реализован |
| user.search | `/?action=user_service.search` | Не реализован |
| user.search_car_logo | `/?action=user_service.search_car_logo` | Не реализован |
| user.search_by_car | `/?action=user_service.search_by_car` | Не реализован |
| user.get_face | `/?action=user_service.getface` | Не реализован |
| user.set_face | `/?action=user_service.setface` | Не реализован |
| user.get | `/?action=user_service.get` | Не реализован |
| friend.add | `/?action=contact_service.add` | Не реализован |
| friend.ensure_add | `/?action=contact_service.ensure_add` | Не реализован |
| version.last | `/?action=vision_service.last` | Не реализован |
| version.latest | `/?action=vision_service.latest` | Не реализован |
| public.users | `/?action=public_service.list` | Не реализован |
| file.upload | `/?action=file_service.upload` | Не реализован |
| file.video | `/?action=file_service.video` | Не реализован |
| lang.get | `/?action=lang_service.get` | Не реализован |
| log.upload | `/?action=log_service.upload` | Не реализован |
| group.quit | `/?action=group_service.quit` | Не реализован |
| group.list | `/?action=group_service.list` | Не реализован |
| group.glist | `/?action=group_service.group_list` | Не реализован |
| group.create | `/?action=group_service.create` | Не реализован |
| group.invite | `/?action=group_service.invite` | Не реализован |
| group.member | `/?action=group_service.member` | Не реализован |
| group.member_detail | `/?action=group_service.member_detail` | Не реализован |
| group.relieve | `/?action=group_service.relieve` | Не реализован |
| group.kicked | `/?action=group_service.kicked` | Не реализован |
| group.update | `/?action=group_service.update` | Не реализован |
| group.save | `/?action=group_service.save` | Не реализован |
| group.group_detail | `/?action=group_service.group_detail` | Не реализован |
| blacklist.add | `/?action=blacklist_service.add` | Не реализован |
| blacklist.list | `/?action=blacklist_service.lists` | Не реализован |
| blacklist.delete | `/?action=blacklist_service.delete` | Не реализован |
| blacklist.is_blacklist | `/?action=blacklist_service.is_blacklist` | Не реализован |
| blacklist.list_car_logo | `/?action=blacklist_service.lists_car_logo` | Не реализован |
| friend.addf | `/?action=friend_service.add` | Не реализован |
| friend.valid_addf | `/?action=friend_service.valid_add` | Не реализован |
| friend.slist | `/?action=friend_service.simple_list` | Не реализован |
| friend.list | `/?action=friend_service.list` | Не реализован |
| friend.ldetail | `/?action=friend_service.detail_list` | Не реализован |
| friend.detail | `/?action=friend_service.detail` | Не реализован |
| friend.waggle | `/?action=friend_service.waggle` | Не реализован |
| friend.delete | `/?action=friend_service.delete` | Не реализован |
| friend.rename | `/?action=friend_service.rename` | Не реализован |
| friend.mobilebook | `/?action=friend_service.mobilebook` | Не реализован |
| friend.get_online_status | `/?action=friend_service.get_online_status` | Не реализован |
| friend.list_car_logo | `/?action=friend_service.list_car_logo` | Не реализован |
| friend.detail_car_logo | `/?action=friend_service.detail_car_logo` | Не реализован |
| friend.waggle_car_logo | `/?action=friend_service.waggle_car_logo` | Не реализован |
| pubaccount.pub_bind_technician | `/?action=pubaccount_service.pub_bind_technician` | Не реализован |
| pubaccount.pub_unbind_technician | `/?action=pubaccount_service.pub_unbind_technician` | Не реализован |
| pubaccount.user_bind_pub | `/?action=pubaccount_service.user_bind_pub` | Не реализован |
| pubaccount.pub_bind_user | `/?action=pubaccount_service.pub_bind_user` | Не реализован |
| pubaccount.user_unbind_pub | `/?action=pubaccount_service.user_unbind_pub` | Не реализован |
| pubaccount.user_attention_pub | `/?action=pubaccount_service.user_attention_pub` | Не реализован |
| pubaccount.user_remove_pub | `/?action=pubaccount_service.user_remove_pub` | Не реализован |
| pubaccount.list_pub | `/?action=pubaccount_service.list_pub` | Не реализован |
| message.send_message_to_pub | `/?action=message_service.send_message_to_pub` | Не реализован |
| pubaccount.pub_detail | `/?action=pubaccount_service.pub_detail` | Не реализован |
| pubaccount.public_detail | `/?action=pubaccount_service.public_detail` | Не реализован |
| pubaccount.search_like | `/?action=pubaccount_service.search_like` | Не реализован |
| pubaccount.pid_byt | `/?action=pubaccount_service.pid_byt` | Не реализован |
| pubaccount.list_tech_byp | `/?action=pubaccount_service.list_tech_byp` | Не реализован |
| pubaccount.apply_expert | `/?action=pubaccount_service.apply_expert` | Не реализован |
| pubaccount.get_expert | `/?action=pubaccount_service.get_expert` | Не реализован |
| product_pub.get_vip_list | `/?action=product_pub_service.get_vip_list` | Не реализован |
| product_pub.apply_bind_pub | `/?action=product_pub_service.apply_bind_pub` | Не реализован |
| product_pub.unbind_pub | `/?action=product_pub_service.unbind_pub` | Не реализован |
| product_pub.pre_bind | `/?action=product_pub_service.pre_bind` | Не реализован |
| product_pub.golo_list_base_info | `/?action=product_pub_service.golo_list_base_info` | Не реализован |
| product_pub.golo_detail_info | `/?action=product_pub_service.golo_detail_info` | Не реализован |
| product_pub.do_with_apply | `/?action=product_pub_service.do_with_apply` | Не реализован |
| product_pub.modify_note | `/?action=product_pub_service.modify_note` | Не реализован |
| product_pub.serial_tech_bind | `/?action=product_pub_service.serial_tech_bind` | Не реализован |
| product_pub.get_tech_by_sn | `/?action=product_pub_service.get_tech_by_sn` | Не реализован |
| public_expert.get_cars | `/?action=public_expert_service.get_cars` | Не реализован |
| public_expert.get_car_classification | `/?action=public_expert_service.get_car_classification` | Не реализован |
| public_expert.get_expert | `/?action=public_expert_service.get_expert` | Не реализован |
| public_expert.get_tech_info | `/?action=public_expert_service.get_tech_info` | Не реализован |
| public_expert.get_tech_detail | `/?action=public_expert_service.get_tech_detail` | Не реализован |
| public_expert.set_expert_info | `/?action=public_expert_service.set_expert_info` | Не реализован |
| public_expert.modify_tech_info | `/?action=public_expert_service.modify_tech_info` | Не реализован |
| public_expert.get_customer_review_for_tech | `/?action=public_expert_service.get_customer_review_for_tech` | Не реализован |
| public_expert.set_tech_customer_review | `/?action=public_expert_service.set_tech_customer_review` | Не реализован |
| public_expert.add_record | `/?action=public_expert_service.add_record` | Не реализован |
| public_expert.add_grade | `/?action=public_expert_service.add_grade` | Не реализован |
| user.getproducts | `/?action=product_service.getproducts` | Не реализован |
| warning.get_items | `/?action=warning_service.get_items` | Не реализован |
| warning.setwarning | `/?action=warning_service.setwarning` | Не реализован |
| warning.set_param | `/?action=warning_service.set_param` | Не реализован |
| datastream.getfaultcodes | `/?action=datastream_service.getfaultcodes` | Не реализован |
| datastream.getsysdatastream | `/?action=datastream_service.getsysdatastream` | Не реализован |
| datastream.getobddatalist | `/?action=datastream_service.getobddatalist` | Не реализован |
| datastream.getdfdatalist | `/?action=datastream_service.getdfdatalist` | Не реализован |
| vehicle.find | `/?action=vehicle_service.find` | Не реализован |
| vehicle.add | `/?action=vehicle_service.add` | Не реализован |
| vehicle.set | `/?action=vehicle_service.set` | Не реализован |
| vehicle.del | `/?action=vehicle_service.del` | Не реализован |
| peccancy.find | `/?action=peccancy_service.find` | Не реализован |
| peccancy.temp_find | `/?action=peccancy_service.temp_find` | Не реализован |
| peccancy.query | `/?action=peccancy_service.query` | Не реализован |
| account.get_data | `/?action=account_record_service.get_data` | Не реализован |
| account.add_amount | `/?action=account_record_service.add_amount` | Не реализован |
| account.mulit_add | `/?action=account_record_service.mulit_add` | Не реализован |
| vehicle.examination | `/?action=datastream_service.examination` | Не реализован |
| configure.get_cars | `/?action=configure_service.get_cars` | Не реализован |
| configure.config_cars | `/?action=configure_service.config_cars` | Не реализован |
| bound_setting.add | `/?action=bounds_setting_service.add_setting` | Не реализован |
| bound_setting.update | `/?action=bounds_setting_service.edit_setting` | Не реализован |
| bound_setting.delete | `/?action=bounds_setting_service.delete_setting` | Не реализован |
| bound_setting.count_by_serial_no | `/?action=bounds_setting_service.count_by_serial_no` | Не реализован |
| bound_setting.get_data | `/?action=bounds_setting_service.get_data` | Не реализован |
| map.map_point_rectify | `/?action=map_service.map_point_rectify` | Не реализован |
| map.get_bd_reverse | `/?action=map_service.get_bd_reverse` | Не реализован |
| gps_info.get_hisitory_position_record | `/?action=gps_info_service.get_hisitory_position_record` | Не реализован |
| gps_info.get_mileage | `/?action=gps_info_service.get_mileage` | Не реализован |
| lbs_mileage_service.get_nearby | `/?action=lbs_mileage_service.get_nearby` | Не реализован |
| gps_info.get_data | `/?action=gps_info_service.get_data` | Не реализован |
| gps_info.get_data2 | `/?action=gps_info_service.get_data2` | Не реализован |
| client.get_history | `/?action=client_service.get_history` | Не реализован |
| client.read | `/?action=client_service.read` | Не реализован |
| warning.get_history | `/?action=warning_service.get_history` | Не реализован |
| gps_info.get_real_time_location | `/?action=gps_info_service.get_real_time_location` | Не реализован |
| gps_info.del_trave | `/?action=gps_info_service.del_trave` | Не реализован |
| public_soft.get_max_version | `/?action=one_key_diag_service.get_public_soft_max_version_by_name` | Не реализован |
| gps_info.get_car_info | `/?action=gps_info_service.get_car_info` | Не реализован |
| gps_info.get_device_status | `/?action=gps_info_service.get_device_status` | Не реализован |
| gps_info.get_trip_record | `/?action=gps_info_service.get_trip_record` | Не реализован |
| mine_car.save_mine_car_info | `/?action=mine_car_service.save_mine_car_info` | Не реализован |
| mine_car.query_mine_car_info | `/?action=mine_car_service.query_mine_car_info` | Не реализован |
| mine_car.get_mine_car_list | `/?action=mine_car_service.get_mine_car_list` | Не реализован |
| mine_car.update_mine_car | `/?action=mine_car_service.update_mine_car` | Не реализован |
| mine_car.remove_mine_car | `/?action=mine_car_service.remove_mine_car` | Не реализован |
| mine_car.query_xdig_car_series | `/?action=mine_car_service.query_xdig_car_series` | Не реализован |
| mine_car.query_car_type | `/?action=mine_car_service.query_car_type` | Не реализован |
| mine_car.query_car_plate_prefix | `/?action=mine_car_service.query_car_plate_prefix` | Не реализован |
| mine_car.query_car_series_config | `/?action=mine_car_service.query_car_series_config` | Не реализован |
| mine_car.clear_mine_car_by_serial_no | `/?action=mine_car_service.clear_mine_car_by_serial_no` | Не реализован |
| mine_car_upload.modify_mine_car_image | `/?action=mine_car_upload_service.modify_mine_car_image` | Не реализован |
| mine_car.query_auto_logos | `/?action=mine_car_service.query_auto_logos` | Не реализован |
| public_message_upload.message_material_upload | `/?action=public_message_upload_service.message_material_upload` | Не реализован |
| product.regist_product | `/?action=product_service.regist_product` | Не реализован |
| product.check_product_update | `/?action=product_service.check_product_update` | Не реализован |
| product.update_user_role | `/?action=product_service.update_user_role` | Не реализован |
| one_key_diag.get_car_brand_list_for_dbscar_pro | `/?action=one_key_diag_service.get_car_brand_list_for_dbscar_pro` | Не реализован |
| one_key_diag.begin_one_key_diag_calc | `/?action=one_key_diag_service.begin_one_key_diag_calc` | Не реализован |
| one_key_diag.one_key_diag_calc | `/?action=one_key_diag_service.one_key_diag_calc` | Не реализован |
| one_key_diag.get_soft_info_for_dbscar_pro | `/?action=one_key_diag_service.get_soft_info_for_dbscar_pro` | Не реализован |
| one_key_diag.get_configed_all_info | `/?action=one_key_diag_service.get_configed_all_info` | Не реализован |
| one_key_diag.get_configed_ini_file_info | `/?action=one_key_diag_service.get_configed_ini_file_info` | Не реализован |
| one_key_diag.get_diag_soft_max_version_by_serial_no | `/?action=one_key_diag_service.get_diag_soft_max_version_by_serial_no` | Не реализован |
| one_key_diag.get_obd_ini_file_info | `/?action=one_key_diag_service.get_obd_ini_file_info` | Не реализован |
| one_key_diag.get_obd_version_detail_id | `/?action=one_key_diag_service.get_obd_version_detail_id` | Не реализован |
| report.upload | `/?action=report_service.upload_report` | Не реализован |
| report.query | `/?action=report_service.query` | Не реализован |
| report.del_medical_report | `/?action=report_service.del_medical_report` | Не реализован |
| report.upload_diagnostic | `/?action=report_service.upload_diagnostic_report` | Не реализован |
| report.query_diagnostic | `/?action=report_service.query_diagnostic_report` | Не реализован |
| share_alarm.set | `/?action=share_alarm_service.set_share` | Не реализован |
| share_alarm.get | `/?action=share_alarm_service.get_share` | Не реализован |
| share_alarm.del | `/?action=share_alarm_service.del_share` | Не реализован |
| post.publish | `/?action=post_service.publish` | Не реализован |
| post.transpond | `/?action=post_service.transpond` | Не реализован |
| post.delete | `/?action=post_service.delete` | Не реализован |
| post.comment_add | `/?action=comment_service.add` | Не реализован |
| post.comment_delete | `/?action=comment_service.delete` | Не реализован |
| post.attitude_add | `/?action=attitude_service.add` | Не реализован |
| post.attitude_delete | `/?action=attitude_service.delete` | Не реализован |
| post.self | `/?action=post_service.self` | Не реализован |
| post.friends | `/?action=post_service.friends` | Не реализован |
| post.get | `/?action=post_service.get` | Не реализован |
| post.message | `/?action=message_service.get` | Не реализован |
| post.file | `/?action=sharefile_service.upload` | Не реализован |
| post.set_config | `/?action=config_service.set` | Не реализован |
| post.get_config | `/?action=config_service.get` | Не реализован |
| post.set_blacklist | `/?action=config_service.set_blacklist` | Не реализован |
| post.get_blacklist | `/?action=config_service.get_blacklist` | Не реализован |
| post.set_nosee | `/?action=config_service.set_nosee` | Не реализован |
| post.get_nosee | `/?action=config_service.get_nosee` | Не реализован |
| getSeriaNoMaxVersion | `/golo/getPubSoftNewVersion.action` | Не реализован |
| updateSerialNoVersion | `/golo/updatePubSoftVersion.action` | Не реализован |
| post.public | `/?action=post_service.public` | Не реализован |
| post.pic | `/?action=post_service.pic` | Не реализован |
| post.set_home | `/?action=config_service.set_home` | Не реализован |
| post.get_home | `/?action=config_service.get_home` | Не реализован |
| post.file_home | `/?action=sharefile_service.home` | Не реализован |
| post.hot_city | `/?action=post_service.hot_city` | Не реализован |
| collect.add | `/?action=collect_service.add` | Не реализован |
| collect.get | `/?action=collect_service.get` | Не реализован |
| collect.delete | `/?action=collect_service.delete` | Не реализован |
| collect.file | `/?action=sharefile_service.collect` | Не реализован |
| onekeydiag.* | `/services/onekeydiag.*Service.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| db.download | `/mobile/softCenter/downloadPublicSoftdb.action` | Не реализован |
| publicsoft.download | `/mobile/softCenter/diagpointdown.php` | PHP download с ACL |
| checkMobilePaypalPayment | `/services/paypalService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| queryCntSynNews | `/services/cntNewsService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getCntSynNews | `/services/cntNewsService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| queryCntSynUpdateInfo | `/services/cntNewsService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getCntSynUpdateInfo | `/services/cntNewsService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| regDeviceToken | `/services/sysAppstoreService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| concernProduct | `/services/concernProductService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| updateMaintanceMillage | `/services/concernProductService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getConcernedProductList | `/services/concernProductService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| updateCustomerInfo | `/services/concernProductService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getConcernedProductSNInfo | `/services/concernProductService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getPushMessageList | `/services/iosPushService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getPushMessageDetailContent | `/services/iosPushService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| setFaultResolvedFlag | `/services/iosPushService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| broadcastMessage | `/services/iosPushService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| pushMessageForSelectedUser | `/services/iosPushService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getCurrentDrivingInfo | `/services/drivingInfoService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getPushMessageListByCC | `/services/iosPushService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| userLogin | `/uc/services/loginservice.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getBinFileMaxVersion | `/services/publicSoftService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| registerProductForPad | `/services/productService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getRegisteredProductsForPad | `/services/productService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| checkPadIIStatus | `/services/productService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getUserIntegrals | `/services/shareService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getUserIntegralHistory | `/services/shareService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getCurrentTypesAndRate | `/services/shareService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getShareUrl | `/services/shareService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| createDiagSoftOrder | `/services/userOrderService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| cancelOrder | `/services/userOrderService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getUserOrderList | `/services/userOrderService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getUserOrderDetailInfo | `/services/userOrderService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| completePay | `/services/userOrderService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getSoftPackageInfo | `/services/userOrderService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| queryAlipayRSATrade | `/services/alipayService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getSoftPrice | `/services/userOrderService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getDiagSoftPrice | `/services/userOrderService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| itunesVerify | `/services/inAppPayService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| createDiagSoftOrderWithIntegration | `/services/userOrderService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getDiagSoftDoc | `/services/diagSoftService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getDiagSoftDescResult | `/services/diagSoftService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| queryLatestDiagSofts | `/services/xdigPadDiagSoftService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| queryPadIIDiagSofts | `/services/xdigPadDiagSoftService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getDiagSoftIdList | `/services/xdigPadDiagSoftService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getDiagSoftInfoBySoftId | `/services/xdigPadDiagSoftService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getValueAddedTaxBillInfoList | `/services/billService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| setOraderBillPostInfo | `/services/billService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| setNormalBillInfo | `/services/billService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getNormalBillInfoList | `/services/billService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| setBillPostInfo | `/services/billService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| setValueAddedTaxBillInfo | `/services/billService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getPostAddressInfoList | `/services/billService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| getOrderBillPostInfo | `/services/billService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| billservice.* | `/services/billService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| paypalservice.* | `/services/paypalService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| cntnewsservice.* | `/services/cntNewsService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| productservice.* | `/services/productService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| userorderservice.* | `/services/userOrderService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| alipayservice.* | `/services/alipayService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| iospushservice.* | `/services/iosPushService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| inapppayservice.* | `/services/inAppPayService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| concernproductservice.* | `/services/concernProductService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| drivinginfoservice.* | `/services/drivingInfoService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| publicsoftservice.* | `/services/publicSoftService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| userservice.* | `/uc/services/userservice.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| bindingservice.* | `/uc/services/bindingservice.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| usersecurityservice.* | `/uc/services/usersecurityservice.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| locationservice.* | `/uc/services/locationservice.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| loginservice.* | `/uc/services/loginservice.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| shareservice.* | `/uc/services/shareService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| diagsoftservice.* | `/uc/services/diagSoftService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| xdigpaddiagsoftservice.* | `/services/xdigPadDiagSoftService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
| xdigpadpublicsoftservice.* | `/services/xdigPadPublicSoftService.php?wsdl` | SOAP dispatcher; только перечисленные методы |
