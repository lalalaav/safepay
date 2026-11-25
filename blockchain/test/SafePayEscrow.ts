import { network } from "hardhat";
import { expect } from "chai";

const { ethers, provider } = await network.connect();

describe("SafePayEscrow (Native ETH)", function () {
  const AMOUNT = ethers.parseEther("0.2"); // Rp 200.000 pada kurs 1 ETH = Rp 1.000.000

  async function deploy() {
    const [buyer, seller, arbiter, stranger] = await ethers.getSigners();
    const escrow = await ethers.deployContract("SafePayEscrow", [arbiter.address]);
    await escrow.waitForDeployment();
    return { buyer, seller, arbiter, stranger, escrow };
  }

  const soon = () => BigInt(Math.floor(Date.now() / 1000) + 3600);

  it("menahan ETH di kontrak, lalu melepas ke penjual setelah konfirmasi", async function () {
    const { buyer, seller, escrow } = await deploy();
    const escrowAddr = await escrow.getAddress();
    const sellerBefore = await ethers.provider.getBalance(seller.address);

    await (await escrow.connect(buyer).createPayment(seller.address, soon(), { value: AMOUNT })).wait();

    expect(await ethers.provider.getBalance(escrowAddr)).to.equal(AMOUNT);
    expect((await escrow.payments(0)).status).to.equal(1n); // Funded

    const shipTx = await escrow.connect(seller).markShipped(0);
    const shipRcpt = await shipTx.wait();
    const shipGas = shipRcpt!.gasUsed * shipRcpt!.gasPrice;
    expect((await escrow.payments(0)).status).to.equal(2n); // Shipped

    await (await escrow.connect(buyer).confirmReceived(0)).wait();

    const sellerAfter = await ethers.provider.getBalance(seller.address);
    expect(sellerAfter).to.equal(sellerBefore - shipGas + AMOUNT);
    expect(await ethers.provider.getBalance(escrowAddr)).to.equal(0n);
    expect((await escrow.payments(0)).status).to.equal(3n); // Released
  });

  it("menolak pihak lain yang mencoba melepas dana atau menandai kirim", async function () {
    const { buyer, seller, stranger, escrow } = await deploy();
    await (await escrow.connect(buyer).createPayment(seller.address, soon(), { value: AMOUNT })).wait();

    await expect(escrow.connect(stranger).markShipped(0)).to.be.revertedWith("not seller");
    await (await escrow.connect(seller).markShipped(0)).wait();

    await expect(escrow.connect(stranger).confirmReceived(0)).to.be.revertedWith("not buyer");
    await expect(escrow.connect(seller).confirmReceived(0)).to.be.revertedWith("not buyer");
  });

  it("menolak pembayaran dengan nilai 0 ETH", async function () {
    const { buyer, seller, escrow } = await deploy();
    await expect(
      escrow.connect(buyer).createPayment(seller.address, soon(), { value: 0n }),
    ).to.be.revertedWith("invalid amount");
  });

  it("menahan ETH selama sengketa dan mengembalikan ke pembeli saat diputus arbiter", async function () {
    const { buyer, seller, arbiter, stranger, escrow } = await deploy();
    const escrowAddr = await escrow.getAddress();
    await (await escrow.connect(buyer).createPayment(seller.address, soon(), { value: AMOUNT })).wait();
    await (await escrow.connect(seller).markShipped(0)).wait();
    await (await escrow.connect(buyer).raiseDispute(0, "barang tidak sesuai")).wait();

    expect((await escrow.payments(0)).status).to.equal(5n); // Disputed
    expect(await ethers.provider.getBalance(escrowAddr)).to.equal(AMOUNT);

    await expect(escrow.connect(buyer).confirmReceived(0)).to.be.revertedWith("not shipped");
    await expect(escrow.connect(buyer).refund(0)).to.be.revertedWith("not refundable");
    await expect(escrow.connect(stranger).resolveDispute(0, false)).to.be.revertedWith("not arbiter");

    const buyerBefore = await ethers.provider.getBalance(buyer.address);
    await (await escrow.connect(arbiter).resolveDispute(0, false)).wait();

    expect(await ethers.provider.getBalance(buyer.address)).to.equal(buyerBefore + AMOUNT);
    expect(await ethers.provider.getBalance(escrowAddr)).to.equal(0n);
    expect((await escrow.payments(0)).status).to.equal(4n); // Refunded
  });

  it("mengizinkan penjual mengajukan sengketa dan arbiter melepas ETH ke penjual", async function () {
    const { buyer, seller, arbiter, escrow } = await deploy();
    const escrowAddr = await escrow.getAddress();
    await (await escrow.connect(buyer).createPayment(seller.address, soon(), { value: AMOUNT })).wait();
    await (await escrow.connect(seller).markShipped(0)).wait();
    await (await escrow.connect(seller).raiseDispute(0, "pembeli tidak kunjung konfirmasi")).wait();

    const sellerBefore = await ethers.provider.getBalance(seller.address);
    await (await escrow.connect(arbiter).resolveDispute(0, true)).wait();

    expect(await ethers.provider.getBalance(seller.address)).to.equal(sellerBefore + AMOUNT);
    expect(await ethers.provider.getBalance(escrowAddr)).to.equal(0n);
    expect((await escrow.payments(0)).status).to.equal(3n); // Released
  });

  it("mengizinkan pembatalan & refund ETH langsung saat masih FUNDED", async function () {
    const { buyer, seller, escrow } = await deploy();
    const escrowAddr = await escrow.getAddress();
    await (await escrow.connect(buyer).createPayment(seller.address, soon(), { value: AMOUNT })).wait();

    await (await escrow.connect(buyer).refund(0)).wait();

    expect(await ethers.provider.getBalance(escrowAddr)).to.equal(0n);
    expect((await escrow.payments(0)).status).to.equal(4n); // Refunded
  });

  it("menerima refund status SHIPPED hanya setelah deadline lewat", async function () {
    const { buyer, seller, escrow } = await deploy();
    const escrowAddr = await escrow.getAddress();
    const tight = BigInt(Math.floor(Date.now() / 1000) + 600);
    await (await escrow.connect(buyer).createPayment(seller.address, tight, { value: AMOUNT })).wait();
    await (await escrow.connect(seller).markShipped(0)).wait();

    await expect(escrow.connect(buyer).refund(0)).to.be.revertedWith("not refundable");

    await provider.send("evm_increaseTime", [1200]);
    await provider.send("evm_mine", []);
    await (await escrow.connect(buyer).refund(0)).wait();

    expect(await ethers.provider.getBalance(escrowAddr)).to.equal(0n);
    expect((await escrow.payments(0)).status).to.equal(4n); // Refunded
  });
});
