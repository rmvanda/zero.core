<?php

    /**
     * Schema:
     *
     *
     * cms:
     *
     * id -- timestamp -- title -- short -- full
     *
     *
     *
     */

    class CmsClientModel {
        public function create() {
            ZXC::ins("cms") -> set() -> go();
        }

        public function read($with, $val) {
            ZXC::sel("id,timestamp,title,short,full/cms<id>cms_aux") -> where($with, $val) -> go();
        }

        public function update($with, $val) {
            ZXC::up("cms") -> where($with, $val) -> go();
        }

        public function delete($with, $val) {
            ZXC::del("cmd") -> where($with, $val) -> go();
        }
    }

    class Cms extends CmsModel {

        public function __construct() {
            Restricted::access();
        }

        public function edit() {

        }

        public function create() {

        }

        public function update() {

        }

        public function read() {

        }

    }
