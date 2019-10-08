<?php
class ArrayObj implements ArrayAccess, JsonSerializable {
	public $data = [];

	public function __construct($obj) {
		$this->setData($obj);
	}
	public function setData($obj) {
		$this->data = (array)$obj;
		if (is_array($obj) || is_object($obj)) {
			foreach ($this->data as &$item) {
				if (is_array($item) || is_object($item)) {
					$item = new ArrayObj($item);
				}
			}
		}
	}
	public function __get($key) {
		return ($this->data[$key]);
	}
	public function __set($key, $val) {
		$this->data[$key] = $val;
	}
	public function __isset($key) {
		return isset($this->data[$key]);
	}
	public function __unset($key) {
		unset($this->data[$key]);
	}
	public function offsetGet($offset) {
		return $this->data[$offset];
	}
	public function offsetSet($offset, $value) {
		if (is_null($offset)) {
			$this->data[] = $value;
		} else {
			$this->data[$offset] = $value;
		}
	}
	public function offsetExists($offset) {
		return isset($this->data[$offset]);
	}
	public function offsetUnset($offset) {
		if ($this->offsetExists($offset)) {
			unset($this->data[$offset]);
		}
	}
	public function __invoke() {
		return $this->asArray();
	}
	public function &asArray() {
		$data = $this->data;
		foreach ($data as $key => &$item) {
			if (is_a($item, __CLASS__, true)) {
				$item = $item->asArray();
			}
		}
		return $data;
	}
	public function jsonSerialize() {
		return $this->data;
	}
	public function __toString() {
		return json_encode($this->data);
	}
	public function find($needle) {
		$haystack = $this->asArray();
		$iterator  = new RecursiveArrayIterator($haystack);
		$recursive = new RecursiveIteratorIterator(
			$iterator,
			RecursiveIteratorIterator::SELF_FIRST
		);
		$return = null;
		foreach ($recursive as $key => $value) {
			if ($key === $needle) {
				$return = $value;
				break;
			}
		}
		
		if (is_array($return) || is_object($return)) {
			$return = new ArrayObj($return);
		}
		return $return;
	}
}