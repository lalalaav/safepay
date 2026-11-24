// SPDX-License-Identifier: MIT
pragma solidity ^0.8.24;

import "@openzeppelin/contracts/token/ERC20/ERC20.sol";

/// Uang simulasi: 1 mIDR = Rp1. decimals 0 supaya nominal tampil bulat.
contract MockIDR is ERC20 {
    constructor() ERC20("Mock Rupiah", "mIDR") {}

    function decimals() public pure override returns (uint8) {
        return 0;
    }

    // ponytail: mint terbuka untuk demo. Batasi ke MINTER_ROLE kalau bukan tugas kelas.
    function mint(address to, uint256 amount) external {
        _mint(to, amount);
    }
}
